'use strict';

/*
 * Luxe WebRTC signaling — brokers browser WebRTC <-> Kurento Media Server.
 * One presenter per stream name (room); many viewers per room. Sub-second latency.
 * Browser talks plain WebRTC + JSON over WS (no browser SDK needed);
 * this app drives KMS via kurento-client.
 */

const http = require('http');
const WebSocket = require('ws');
const kurento = require('kurento-client');

const CFG = {
  wsPort:  8443,
  wsHost:  '127.0.0.1',          // behind nginx TLS on dashboard.divafans.club
  wsPath:  '/kurento-signal',
  kms:     'ws://127.0.0.1:8888/kurento',
  stunAddr:'162.35.180.58',
  stunPort:3478,
  turnUrl: 'luxe:Turn7700Luxe@162.35.180.58:3478'   // user:pass@host:port ('' to disable)
};

let kurentoClient = null;
const rooms = {};       // name -> { pipeline, presenter:{sessionId,endpoint}, viewers:{sessionId:{endpoint}} }
const sessions = {};    // sessionId -> { ws, name, role, candQueue:[] }
let seq = 0;

const clean = s => String(s || '').replace(/[^a-zA-Z0-9_-]/g, '') || 'stream1';
const send  = (ws, obj) => { try { if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj)); } catch (e) {} };

const server = http.createServer((req, res) => {
  if ((req.url || '').split('?')[0] === '/rooms') {
    const out = Object.keys(rooms).map(n => ({ name:n, viewers:Object.keys(rooms[n].viewers).length, live: !!rooms[n].presenter }));
    res.writeHead(200, {'Content-Type':'application/json'});
    return res.end(JSON.stringify({ ok:true, rooms:out }));
  }
  res.writeHead(200, {'Content-Type':'text/plain'}); res.end('kurento-signal up\n');
});
const wss = new WebSocket.Server({ server, path: CFG.wsPath });

function getKurento(cb) {
  if (kurentoClient) return cb(null, kurentoClient);
  kurento(CFG.kms, (err, client) => {
    if (err) { console.error('[KMS] connect error:', err.message || err); return cb(err); }
    kurentoClient = client;
    client.on('disconnect', () => { console.error('[KMS] disconnected'); kurentoClient = null; });
    cb(null, client);
  });
}

function configureIce(ep) {
  ep.setStunServerAddress(CFG.stunAddr, () => {});
  ep.setStunServerPort(CFG.stunPort, () => {});
  if (CFG.turnUrl) ep.setTurnUrl(CFG.turnUrl, () => {});
}

wss.on('connection', (ws) => {
  const id = 's' + (++seq);
  sessions[id] = { ws, name: null, role: null, candQueue: [] };
  ws.on('close', () => stop(id));
  ws.on('error', () => stop(id));
  ws.on('message', (raw) => {
    let m; try { m = JSON.parse(raw); } catch (e) { return; }
    switch (m.id) {
      case 'presenter':      onPresenter(id, clean(m.name), m.sdpOffer); break;
      case 'viewer':         onViewer(id, clean(m.name), m.sdpOffer);    break;
      case 'onIceCandidate': onIce(id, m.candidate);                     break;
      case 'stop':           stop(id);                                   break;
      default: break;
    }
  });
});

function onPresenter(id, name, sdpOffer) {
  const s = sessions[id]; if (!s) return;
  if (rooms[name] && rooms[name].presenter) {
    return send(s.ws, { id:'presenterResponse', response:'rejected', message:'Someone is already live on "' + name + '".' });
  }
  s.name = name; s.role = 'presenter';
  getKurento((err, client) => {
    if (err || !sessions[id]) return send(s.ws, { id:'presenterResponse', response:'rejected', message:'Media server unavailable.' });
    client.create('MediaPipeline', (err, pipeline) => {
      if (err) return send(s.ws, { id:'presenterResponse', response:'rejected', message:String(err) });
      if (!sessions[id]) { pipeline.release(); return; }
      pipeline.create('WebRtcEndpoint', (err, ep) => {
        if (err) { pipeline.release(); return send(s.ws, { id:'presenterResponse', response:'rejected', message:String(err) }); }
        if (!sessions[id]) { pipeline.release(); return; }
        rooms[name] = { pipeline, presenter:{ sessionId:id, endpoint:ep }, viewers:{} };
        configureIce(ep);
        s.candQueue.forEach(c => ep.addIceCandidate(c)); s.candQueue = [];
        ep.on('IceCandidateFound', (ev) => send(s.ws, { id:'iceCandidate', candidate: kurento.getComplexType('IceCandidate')(ev.candidate) }));
        ep.processOffer(sdpOffer, (err, sdpAnswer) => {
          if (err) return send(s.ws, { id:'presenterResponse', response:'rejected', message:String(err) });
          if (!sessions[id]) return;
          send(s.ws, { id:'presenterResponse', response:'accepted', sdpAnswer });
        });
        ep.gatherCandidates((err) => { if (err) console.error('[presenter] gather:', err); });
      });
    });
  });
}

function onViewer(id, name, sdpOffer) {
  const s = sessions[id]; if (!s) return;
  const room = rooms[name];
  if (!room || !room.presenter) {
    return send(s.ws, { id:'viewerResponse', response:'rejected', message:'No live stream on "' + name + '" right now.' });
  }
  s.name = name; s.role = 'viewer';
  room.pipeline.create('WebRtcEndpoint', (err, ep) => {
    if (err) return send(s.ws, { id:'viewerResponse', response:'rejected', message:String(err) });
    if (!sessions[id] || !rooms[name] || !rooms[name].presenter) { ep.release(); return; }
    room.viewers[id] = { endpoint: ep };
    configureIce(ep);
    s.candQueue.forEach(c => ep.addIceCandidate(c)); s.candQueue = [];
    ep.on('IceCandidateFound', (ev) => send(s.ws, { id:'iceCandidate', candidate: kurento.getComplexType('IceCandidate')(ev.candidate) }));
    ep.processOffer(sdpOffer, (err, sdpAnswer) => {
      if (err) return send(s.ws, { id:'viewerResponse', response:'rejected', message:String(err) });
      room.presenter.endpoint.connect(ep, (err) => {
        if (err) return send(s.ws, { id:'viewerResponse', response:'rejected', message:String(err) });
        if (!sessions[id]) return;
        send(s.ws, { id:'viewerResponse', response:'accepted', sdpAnswer });
      });
    });
    ep.gatherCandidates((err) => { if (err) console.error('[viewer] gather:', err); });
  });
}

function onIce(id, candidate) {
  const s = sessions[id]; if (!s) return;
  const cand = kurento.getComplexType('IceCandidate')(candidate);
  const room = s.name ? rooms[s.name] : null;
  let ep = null;
  if (room) {
    if (s.role === 'presenter' && room.presenter && room.presenter.sessionId === id) ep = room.presenter.endpoint;
    else if (s.role === 'viewer' && room.viewers[id]) ep = room.viewers[id].endpoint;
  }
  if (ep) ep.addIceCandidate(cand); else s.candQueue.push(cand);
}

function stop(id) {
  const s = sessions[id]; if (!s) return;
  const room = s.name ? rooms[s.name] : null;
  if (room) {
    if (s.role === 'presenter' && room.presenter && room.presenter.sessionId === id) {
      Object.keys(room.viewers).forEach(vid => {
        const vs = sessions[vid];
        if (vs) { send(vs.ws, { id:'stopCommunication' }); vs.name = null; vs.role = null; }
      });
      if (room.pipeline) room.pipeline.release();
      delete rooms[s.name];
    } else if (s.role === 'viewer' && room.viewers[id]) {
      try { room.viewers[id].endpoint.release(); } catch (e) {}
      delete room.viewers[id];
    }
  }
  delete sessions[id];
}

server.listen(CFG.wsPort, CFG.wsHost, () => {
  console.log('[luxe-kurento] signaling on ws://' + CFG.wsHost + ':' + CFG.wsPort + CFG.wsPath);
  console.log('[luxe-kurento] KMS ' + CFG.kms + ' | TURN ' + (CFG.turnUrl ? 'on' : 'off'));
});

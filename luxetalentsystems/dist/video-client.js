/* Twilio Video client — shared by admin + performer pages.
   Loads the Twilio Video SDK, connects to a room, renders local + remote tiles.
   Room model: one room per performer (room name = performer email slug).
   Admin joins the performer's room -> two-way admin <-> performer. */
(function(){
  var API = 'https://luxetalentsystems.com/api';
  var SDK_URL = 'https://sdk.twilio.com/js/video/releases/2.28.1/twilio-video.min.js';
  var activeRoom = null;

  function roomNameFor(performerEmail){
    return 'perf_' + String(performerEmail||'').toLowerCase().replace(/[^a-z0-9]/g,'_');
  }

  function loadSDK(){
    return new Promise(function(resolve, reject){
      if(window.Twilio && window.Twilio.Video) return resolve(window.Twilio.Video);
      var sc = document.createElement('script');
      sc.src = SDK_URL;
      sc.onload = function(){ (window.Twilio && window.Twilio.Video) ? resolve(window.Twilio.Video) : reject(new Error('SDK failed to load')); };
      sc.onerror = function(){ reject(new Error('Could not load Twilio Video SDK')); };
      document.head.appendChild(sc);
    });
  }

  function getToken(identity, room){
    return fetch(API + '/video.php?action=token', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ identity: identity, room: room })
    }).then(function(r){ return r.json(); }).then(function(d){
      if(!d || !d.success) throw new Error((d && d.error) || 'Token request failed');
      return d.token;
    });
  }

  function attachTrack(track, container){
    if(track.kind === 'video' || track.kind === 'audio'){
      var el = track.attach();
      if(track.kind === 'video'){ el.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:10px;background:#000'; }
      container.appendChild(el);
    }
  }
  function clearEl(el){ if(el){ while(el.firstChild) el.removeChild(el.firstChild); } }

  function handleParticipant(participant, remoteEl){
    function add(pub){ if(pub.track) attachTrack(pub.track, remoteEl); }
    participant.tracks.forEach(function(pub){ add(pub); });
    participant.on('trackSubscribed', function(track){ attachTrack(track, remoteEl); });
    participant.on('trackUnsubscribed', function(track){ track.detach().forEach(function(el){ el.remove(); }); });
  }

  /* opts: { identity, performerEmail, localEl, remoteEl, onStatus, onEnd } */
  window.startVideo = async function(opts){
    var Video, token;
    var room = roomNameFor(opts.performerEmail);
    if(opts.onStatus) opts.onStatus('Loading...');
    try {
      Video = await loadSDK();
      token = await getToken(opts.identity, room);
    } catch(e){ if(opts.onStatus) opts.onStatus('Error: ' + e.message); return; }

    if(opts.onStatus) opts.onStatus('Connecting...');
    try {
      var connected = await Video.connect(token, { name: room, audio: true, video: { width: 640 } });
      activeRoom = connected;

      // Local preview
      clearEl(opts.localEl);
      connected.localParticipant.tracks.forEach(function(pub){ if(pub.track) attachTrack(pub.track, opts.localEl); });

      // Existing + future remote participants
      clearEl(opts.remoteEl);
      connected.participants.forEach(function(p){ handleParticipant(p, opts.remoteEl); });
      connected.on('participantConnected', function(p){ handleParticipant(p, opts.remoteEl); if(opts.onStatus) opts.onStatus('Connected'); });
      connected.on('participantDisconnected', function(){ if(opts.onStatus) opts.onStatus('Waiting for other side...'); });

      connected.on('disconnected', function(){
        connected.localParticipant.tracks.forEach(function(pub){ if(pub.track){ pub.track.stop(); pub.track.detach().forEach(function(el){ el.remove(); }); } });
        activeRoom = null;
        if(opts.onEnd) opts.onEnd();
      });

      if(opts.onStatus) opts.onStatus(connected.participants.size ? 'Connected' : 'Waiting for other side...');
    } catch(e){ if(opts.onStatus) opts.onStatus('Connect failed: ' + e.message); }
  };

  window.stopVideo = function(){ if(activeRoom){ activeRoom.disconnect(); activeRoom = null; } };

  /* Toggle local mic/camera. Returns the new enabled state (true=on), or null if not in a call. */
  window.videoToggleAudio = function(){
    if(!activeRoom) return null; var on = null;
    activeRoom.localParticipant.audioTracks.forEach(function(pub){
      if(pub.track.isEnabled){ pub.track.disable(); on = false; } else { pub.track.enable(); on = true; }
    });
    return on;
  };
  window.videoToggleVideo = function(){
    if(!activeRoom) return null; var on = null;
    activeRoom.localParticipant.videoTracks.forEach(function(pub){
      if(pub.track.isEnabled){ pub.track.disable(); on = false; } else { pub.track.enable(); on = true; }
    });
    return on;
  };
})();

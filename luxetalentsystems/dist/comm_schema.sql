-- ═══════════════════════════════════════════════════════════════
-- Communications module schema (Voice / SMS)
-- Safe to run repeatedly: CREATE TABLE IF NOT EXISTS.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS call_log (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  direction       VARCHAR(10)  NOT NULL DEFAULT 'outbound',   -- inbound | outbound
  phone           VARCHAR(32)  NOT NULL DEFAULT '',
  performer_email VARCHAR(190) NOT NULL DEFAULT '',
  twilio_sid      VARCHAR(64)  NOT NULL DEFAULT '',
  status          VARCHAR(32)  NOT NULL DEFAULT '',
  duration        INT          NOT NULL DEFAULT 0,            -- seconds
  admin_phone     VARCHAR(32)  NOT NULL DEFAULT '',
  recording_url   VARCHAR(255) NOT NULL DEFAULT '',
  recording_sid   VARCHAR(64)  NOT NULL DEFAULT '',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_phone (phone),
  KEY idx_email (performer_email),
  KEY idx_sid (twilio_sid),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_messages (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  direction       VARCHAR(10)  NOT NULL DEFAULT 'outbound',   -- inbound | outbound
  phone           VARCHAR(32)  NOT NULL DEFAULT '',
  body            TEXT,
  twilio_sid      VARCHAR(64)  NOT NULL DEFAULT '',
  status          VARCHAR(32)  NOT NULL DEFAULT '',
  performer_email VARCHAR(190) NOT NULL DEFAULT '',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_phone (phone),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IVR menu config (single active menu; one row per digit 0-9, plus a greeting row key='greeting')
CREATE TABLE IF NOT EXISTS ivr_menu (
  digit       VARCHAR(10)  NOT NULL PRIMARY KEY,   -- 'greeting' or '0'..'9'
  action_type VARCHAR(20)  NOT NULL DEFAULT 'say', -- say | dial | voicemail
  say_text    TEXT,                                -- prompt or message
  dial_number VARCHAR(32)  NOT NULL DEFAULT '',     -- for action_type=dial
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a default menu if empty
INSERT IGNORE INTO ivr_menu (digit, action_type, say_text, dial_number) VALUES
  ('greeting', 'say', 'Thank you for calling. For an agent, press 1. To leave a message, press 2.', ''),
  ('1', 'dial', '', ''),
  ('2', 'voicemail', 'Please leave a message after the beep.', '');

-- Video call requests (performer -> admin incoming-call signaling)
CREATE TABLE IF NOT EXISTS video_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  performer_email VARCHAR(190) NOT NULL DEFAULT '',
  stage_name      VARCHAR(190) NOT NULL DEFAULT '',
  room            VARCHAR(120) NOT NULL DEFAULT '',
  status          VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending | accepted | declined | cancelled
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at     TIMESTAMP    NULL DEFAULT NULL,
  KEY idx_status (status),
  KEY idx_perf (performer_email),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

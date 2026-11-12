-- Letters: HR's templates, letters made from them, signatories, approval, issue (frozen text + verification code),
-- the person's answer through a private link, employee requests. Nothing is deleted: templates and signatories are
-- switched off, letters are withdrawn with a reason, every step goes into letter_log (append-only).

CREATE TABLE IF NOT EXISTS letter_template (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL UNIQUE,          -- used in the reference number
  name VARCHAR(80) NOT NULL,
  body TEXT NOT NULL,                        -- plain text with {{fields}}; blank line = new paragraph, "- " = bullet
  resp VARCHAR(8) NOT NULL DEFAULT 'none',   -- none | accept | ack
  signer_role VARCHAR(20) NOT NULL DEFAULT 'HRMANAGER',
  needs_approval TINYINT(1) NOT NULL DEFAULT 0,
  for_employees TINYINT(1) NOT NULL DEFAULT 1,   -- shown on the person's own letters page
  active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(200) NOT NULL DEFAULT '',
  updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO letter_template (code, name, body, resp, signer_role, needs_approval, for_employees)
SELECT * FROM (
 SELECT 'OFFER' c, 'Offer letter' n, 'Dear {{name}},\n\nWe are pleased to offer you the position of {{designation}} in the {{department}} department at {{branch}}, {{company}}.\n\nYour proposed date of joining is {{doj}}. Your compensation is as set out in the annexure: {{ctc}}.\n\nPlease accept this offer by {{accept_by}}.\n\nWe look forward to welcoming you.' b, 'accept' r, 'HRMANAGER' s, 0 a, 0 e UNION ALL
 SELECT 'APPT', 'Appointment letter', 'Dear {{name}},\n\nFurther to your acceptance of our offer, we are pleased to appoint you as {{designation}} in the {{department}} department at {{branch}}, with effect from {{doj}}. Your employee code is {{code}}.\n\n- You will be on probation for {{probation_months}} months from the date of joining.\n- Your terms of employment are as set out in the company policies, as amended from time to time.\n\nPlease sign and return a copy of this letter as your acceptance.', 'accept', 'HRMANAGER', 0, 1 UNION ALL
 SELECT 'CONF', 'Confirmation letter', 'Dear {{name}},\n\nWe are pleased to confirm your employment as {{designation}} with effect from {{confirm_date}}.\n\nWe appreciate your contribution and wish you continued success.', 'ack', 'HRMANAGER', 0, 1 UNION ALL
 SELECT 'EMPCERT', 'Employment certificate', 'To whom it may concern,\n\nThis is to certify that {{name}} (employee code {{code}}) has been working with {{company}} as {{designation}} in the {{department}} department since {{doj}}.\n\nThis certificate is issued at their request for {{purpose}}.', 'none', 'HRMANAGER', 0, 1 UNION ALL
 SELECT 'EXPCERT', 'Experience certificate', 'To whom it may concern,\n\nThis is to certify that {{name}} (employee code {{code}}) worked with {{company}} as {{designation}} from {{doj}} to {{last_day}}.\n\nWe wish them success in their future endeavours.', 'none', 'HRMANAGER', 0, 1 UNION ALL
 SELECT 'RELIEVE', 'Relieving letter', 'Dear {{name}},\n\nThis is to confirm that you have been relieved from your duties as {{designation}} at the close of business on {{last_day}}, after completing the required handover.\n\nWe thank you for your contribution and wish you well.', 'ack', 'HRMANAGER', 0, 1
) x WHERE NOT EXISTS (SELECT 1 FROM letter_template);

CREATE TABLE IF NOT EXISTS letter_signer (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  title VARCHAR(120) NOT NULL,
  from_day DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  set_by INT NOT NULL, set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_role (role_code, from_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS letter (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  type_code VARCHAR(12) NOT NULL,
  ref VARCHAR(60) NULL UNIQUE,               -- given at issue
  person VARCHAR(160) NOT NULL,
  emp_code VARCHAR(32) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  fields TEXT NOT NULL,                      -- JSON of the values typed / taken
  status VARCHAR(10) NOT NULL DEFAULT 'draft',   -- draft | approval | approved | issued | withdrawn | lapsed
  resp VARCHAR(8) NOT NULL DEFAULT 'none',
  accept_by DATE NULL,
  signer_role VARCHAR(20) NOT NULL,
  signer_name VARCHAR(120) NOT NULL DEFAULT '', signer_title VARCHAR(120) NOT NULL DEFAULT '',
  approver_id INT NULL, approved_at DATETIME NULL, approve_note VARCHAR(500) NOT NULL DEFAULT '',
  body_html MEDIUMTEXT NULL,                 -- frozen at issue
  sha256 CHAR(64) NULL,
  verify_code CHAR(10) NULL UNIQUE,
  token_hash CHAR(64) NULL, link_until DATE NULL,
  response VARCHAR(4) NULL, resp_name VARCHAR(160) NULL, resp_at DATETIME NULL, resp_ip VARCHAR(45) NULL,
  request_id INT NULL,
  withdrawn_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by INT NULL, issued_at DATETIME NULL,
  KEY k_code (emp_code), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS letter_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  text VARCHAR(300) NOT NULL,
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_letter (letter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS letter_request (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  user_id INT NOT NULL,
  template_id INT NOT NULL,
  purpose VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(8) NOT NULL DEFAULT 'open',  -- open | done | closed
  letter_id INT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  done_by INT NULL, done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

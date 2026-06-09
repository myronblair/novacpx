-- Migration 009: Email Templates
CREATE TABLE IF NOT EXISTS email_templates (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  trigger_key TEXT NOT NULL UNIQUE,
  label       TEXT NOT NULL,
  subject     TEXT NOT NULL,
  body_html   TEXT NOT NULL,
  body_text   TEXT,
  enabled     INTEGER DEFAULT 1,
  updated_at  TEXT DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO email_templates (trigger_key, label, subject, body_html, body_text) VALUES
  ('account_created', 'Account Created (to user)', 'Your NovaCPX hosting account is ready',
   '<h2>Welcome, {{name}}!</h2><p>Your hosting account for <strong>{{domain}}</strong> has been created.</p><p><b>Login:</b> {{username}}<br><b>Password:</b> {{password}}</p><p>Log in at <a href="{{panel_url}}">{{panel_url}}</a></p>',
   'Welcome, {{name}}! Your hosting account for {{domain}} has been created. Login: {{username}} / {{password}} at {{panel_url}}'),
  ('account_created_admin', 'Account Created (to admin)', 'New account created: {{domain}}',
   '<h2>New account created</h2><p><b>Domain:</b> {{domain}}<br><b>User:</b> {{username}}<br><b>Package:</b> {{package}}<br><b>Created by:</b> {{created_by}}</p>',
   'New account created: domain={{domain}} user={{username}} package={{package}}'),
  ('account_suspended', 'Account Suspended (to user)', 'Your hosting account has been suspended',
   '<h2>Account Suspended</h2><p>Your hosting account for <strong>{{domain}}</strong> has been suspended.</p><p>Reason: {{reason}}</p><p>Contact <a href="mailto:{{support_email}}">{{support_email}}</a> to resolve this.</p>',
   'Your account for {{domain}} has been suspended. Reason: {{reason}}. Contact {{support_email}}.'),
  ('account_terminated', 'Account Terminated (to user)', 'Your hosting account has been terminated',
   '<h2>Account Terminated</h2><p>Your hosting account for <strong>{{domain}}</strong> has been permanently terminated. All data has been deleted.</p>',
   'Your account for {{domain}} has been permanently terminated.'),
  ('password_reset', 'Password Reset', 'NovaCPX password reset request',
   '<h2>Password Reset</h2><p>A password reset was requested for your account. Click below to reset your password:</p><p><a href="{{reset_url}}">Reset Password</a></p><p>This link expires in 1 hour. If you did not request this, ignore this email.</p>',
   'Password reset requested. Visit {{reset_url}} to reset your password (expires in 1 hour).'),
  ('ssl_expiring', 'SSL Certificate Expiring', 'SSL certificate for {{domain}} expires in {{days}} days',
   '<h2>SSL Certificate Expiring Soon</h2><p>The SSL certificate for <strong>{{domain}}</strong> will expire in <strong>{{days}} days</strong> on {{expiry_date}}.</p><p>Log in to renew: <a href="{{panel_url}}">{{panel_url}}</a></p>',
   'SSL certificate for {{domain}} expires in {{days}} days on {{expiry_date}}.'),
  ('disk_warning', 'Disk Usage Warning', 'Disk usage warning for {{domain}}: {{usage}}% used',
   '<h2>Disk Usage Warning</h2><p>Your hosting account for <strong>{{domain}}</strong> is at <strong>{{usage}}%</strong> disk capacity ({{used}} of {{quota}} used).</p><p>Please free up space or upgrade your package.</p>',
   'Disk warning: {{domain}} is at {{usage}}% capacity ({{used}} of {{quota}}).'),
  ('smtp_test', 'SMTP Test', 'NovaCPX SMTP Test Email',
   '<h2>SMTP Test Successful</h2><p>This is a test email from <strong>NovaCPX</strong>. If you received this, your SMTP configuration is working correctly.</p>',
   'NovaCPX SMTP test email. SMTP is configured correctly.');

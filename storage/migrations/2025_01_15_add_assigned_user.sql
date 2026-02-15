-- Adds assigned user fields for clients and leads
ALTER TABLE klienci ADD COLUMN assigned_user_id INT NULL;
CREATE INDEX idx_klienci_assigned_user_id ON klienci(assigned_user_id);

ALTER TABLE leady ADD COLUMN assigned_user_id INT NULL;
CREATE INDEX idx_leady_assigned_user_id ON leady(assigned_user_id);

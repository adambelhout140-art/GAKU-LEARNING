-- Temporarily keep the old values while expanding the enum.
ALTER TABLE contact_requests
    MODIFY status ENUM('new', 'in_progress', 'closed', 'accepted', 'refused')
    NOT NULL DEFAULT 'new';

UPDATE contact_requests
SET status = CASE
    WHEN status = 'in_progress' THEN 'accepted'
    WHEN status = 'closed' THEN 'refused'
    ELSE status
END;

ALTER TABLE contact_requests
    MODIFY status ENUM('new', 'accepted', 'refused')
    NOT NULL DEFAULT 'new';

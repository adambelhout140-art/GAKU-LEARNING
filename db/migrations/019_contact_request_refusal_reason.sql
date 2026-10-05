ALTER TABLE contact_requests
    ADD COLUMN refusal_reason TEXT NULL AFTER status;

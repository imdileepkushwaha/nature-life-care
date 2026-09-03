-- Member profile photo
ALTER TABLE members
    ADD COLUMN photo VARCHAR(255) NULL AFTER phone;

-- Repair the seeded OSA Staff password hash.
-- The documented demo password is password123.
UPDATE users
SET password = '$2y$10$/MDIHv1RbHGJOdezALQ14eZJR2.LAVXotornqoACp4AeWEjo6Onf.'
WHERE username = 'osa.staff';
-- 3.37: the work phone of a user account (My account; administrators in Users). It is shown to colleagues in My account →
-- Colleagues and goes into the fallback e-mail signature of a person who has no record in the Team collection
-- (Builder\UserSignature). The position (pozice) was there already. The code of 3.36 reads nothing of it. A column that
-- already exists (an earlier attempt) is skipped by the migration runner (1060).
ALTER TABLE ka_uzivatele ADD COLUMN phone VARCHAR(40) NOT NULL DEFAULT '';

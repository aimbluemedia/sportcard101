-- Monthly membership moves from $19 to $9.
--
-- Only touches a plan still sitting on the installer's seeded price, so a
-- price an admin set deliberately is left alone. Safe to run more than once.
UPDATE plans
   SET price_cents = 900
 WHERE price_cents = 1900
   AND bill_interval = 'month';

-- ============================================================
-- reset_recommendations.sql
-- Clears out the sample/demo recommendations (and the sample
-- predictions that produced them) so the Recommendations page
-- starts empty. Real predictions you make going forward via
-- predict.php will repopulate both tables automatically.
--
-- Run once:  mysql -u root -p sugar_demand_db < database/reset_recommendations.sql
-- ============================================================

USE sugar_demand_db;

-- recommendations has a FK to predictions, so clear it first
TRUNCATE TABLE recommendations;
TRUNCATE TABLE predictions;

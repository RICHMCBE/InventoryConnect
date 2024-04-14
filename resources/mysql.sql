-- #! mysql
-- #{ inventory
-- #    { initialization
CREATE TABLE IF NOT EXISTS inventory(
xuid BIGINT UNSIGNED NOT NULL PRIMARY KEY comment 'players xuid',
inventoryData MEDIUMBLOB NOT NULL comment 'players inventory data',
INDEX idx_xuid(xuid)
) charset=utf8 comment='players inventory';
-- #    }
-- #    { save
-- #        :xuid int
-- #        :inventoryData string
INSERT INTO inventory(xuid, inventoryData) VALUES (:xuid, :inventoryData) ON DUPLICATE KEY UPDATE inventoryData = :inventoryData
-- #    }
-- #    { load
-- #        :xuid int
SELECT inventoryData FROM inventory WHERE xuid = :xuid LIMIT 1
-- #    }
-- #}

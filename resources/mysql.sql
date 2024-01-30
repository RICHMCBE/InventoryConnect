-- #! mysql
-- #{ inventory
-- #    { initialization
CREATE TABLE IF NOT EXISTS inventory(
xuid BIGINT NOT NULL PRIMARY KEY comment 'players xuid',
inventoryData LONGTEXT NOT NULL comment 'players inventory data'
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

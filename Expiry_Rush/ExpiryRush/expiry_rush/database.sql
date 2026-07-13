DROP DATABASE IF EXISTS expiry_rush;
CREATE DATABASE expiry_rush CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE expiry_rush;

CREATE TABLE users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)  NOT NULL,
    email      VARCHAR(150)  NOT NULL UNIQUE,
    password   VARCHAR(255)  NOT NULL,
    role       ENUM('customer','seller','admin') NOT NULL DEFAULT 'customer',
    phone      VARCHAR(20),
    address    TEXT,
    is_active  TINYINT(1)    NOT NULL DEFAULT 1,
    created_at DATETIME      DEFAULT NOW()
);

CREATE TABLE categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at  DATETIME     DEFAULT NOW()
);

CREATE TABLE products (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    seller_id   INT            NOT NULL,
    category_id INT            NOT NULL,
    name        VARCHAR(200)   NOT NULL,
    description TEXT,
    base_price  DECIMAL(10,2)  NOT NULL,
    stock       INT            NOT NULL DEFAULT 0,
    listed_at   DATETIME       DEFAULT NOW(),
    expires_at  DATETIME       NOT NULL,
    is_active   TINYINT(1)     NOT NULL DEFAULT 1,
    FOREIGN KEY (seller_id)   REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE price_tiers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    product_id          INT           NOT NULL,
    hours_before_expiry INT           NOT NULL,
    discount_pct        DECIMAL(5,2)  NOT NULL,
    created_at          DATETIME      DEFAULT NOW(),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_product_hours (product_id, hours_before_expiry)
);

CREATE TABLE cart (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT           NOT NULL,
    product_id      INT           NOT NULL,
    quantity        INT           NOT NULL DEFAULT 1,
    locked_price    DECIMAL(10,2) NOT NULL,
    lock_expires_at DATETIME      NOT NULL,
    created_at      DATETIME      DEFAULT NOW(),
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (product_id)  REFERENCES products(id),
    UNIQUE KEY uq_customer_product (customer_id, product_id)
);

-- orders: includes delivery fields + full delivery status flow
CREATE TABLE orders (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    customer_id      INT           NOT NULL,
    total_amount     DECIMAL(10,2) NOT NULL,
    status           ENUM('pending','processing','out_for_delivery','delivered','cancelled')
                     NOT NULL DEFAULT 'pending',
    delivery_name    VARCHAR(100)  NOT NULL DEFAULT '',
    delivery_phone   VARCHAR(20)   NOT NULL DEFAULT '',
    delivery_address VARCHAR(255)  NOT NULL DEFAULT '',
    delivery_city    VARCHAR(100)  NOT NULL DEFAULT '',
    notes            TEXT,
    created_at       DATETIME      DEFAULT NOW(),
    FOREIGN KEY (customer_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT           NOT NULL,
    product_id   INT           NOT NULL,
    product_name VARCHAR(200)  NOT NULL DEFAULT '',
    quantity     INT           NOT NULL DEFAULT 1,
    unit_price   DECIMAL(10,2) NOT NULL,
    discount_pct DECIMAL(5,2)  DEFAULT 0,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- payments: COD only — method default 'cod', status 'pending' until delivered
CREATE TABLE payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT           NOT NULL UNIQUE,
    amount          DECIMAL(10,2) NOT NULL,
    method          ENUM('cod','card','wallet','cash') NOT NULL DEFAULT 'cod',
    status          ENUM('pending','success','failed')  NOT NULL DEFAULT 'pending',
    transaction_ref VARCHAR(100),
    paid_at         DATETIME,
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE TABLE rush_alerts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    product_id      INT          NOT NULL,
    target_discount INT          NOT NULL DEFAULT 30,
    triggered       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     DEFAULT NOW(),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    UNIQUE KEY uq_user_product (user_id, product_id)
);

-- order_tracking: full history log of every status change
CREATE TABLE order_tracking (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    order_id   INT          NOT NULL,
    status     VARCHAR(50)  NOT NULL,
    note       VARCHAR(255) DEFAULT '',
    changed_by INT          NOT NULL,
    changed_at DATETIME     DEFAULT NOW(),
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
);



CREATE OR REPLACE VIEW active_products AS
SELECT
    p.*,
    c.name  AS category_name,
    c.id    AS cat_id,
    u.name  AS seller_name,
    TIMESTAMPDIFF(SECOND, NOW(), p.expires_at) AS seconds_left,
    GREATEST(0, LEAST(90,
        ROUND(
            (1 - (TIMESTAMPDIFF(SECOND, NOW(), p.expires_at) /
                  NULLIF(TIMESTAMPDIFF(SECOND, p.listed_at, p.expires_at), 0))
            ) * 90
        )
    )) AS discount_percent,
    ROUND(
        p.base_price * (
            1 - GREATEST(0, LEAST(90,
                ROUND(
                    (1 - (TIMESTAMPDIFF(SECOND, NOW(), p.expires_at) /
                          NULLIF(TIMESTAMPDIFF(SECOND, p.listed_at, p.expires_at), 0))
                    ) * 90
                )
            )) / 100
        ),
    2) AS current_price
FROM products p
JOIN categories c ON p.category_id = c.id
JOIN users      u ON p.seller_id   = u.id
WHERE p.is_active  = 1
  AND p.expires_at > NOW()
  AND p.stock      > 0;

CREATE OR REPLACE VIEW expiring_soon AS
SELECT * FROM active_products
WHERE seconds_left <= 21600
ORDER BY seconds_left ASC;

CREATE OR REPLACE VIEW order_summary AS
SELECT    o.id             AS order_id,
    o.created_at,
    o.status,
    o.total_amount,
    o.delivery_name,
    o.delivery_phone,
    o.delivery_address,
    o.delivery_city,
    u.name           AS customer_name,
    u.email          AS customer_email,
    COUNT(oi.id)     AS item_count,
    GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS items
FROM orders o
JOIN users       u  ON o.customer_id = u.id
JOIN order_items oi ON oi.order_id   = o.id
GROUP BY o.id, o.created_at, o.status, o.total_amount,
         o.delivery_name, o.delivery_phone, o.delivery_address, o.delivery_city,
         u.name, u.email;



DELIMITER $$


DROP PROCEDURE IF EXISTS sp_place_order$$

CREATE PROCEDURE sp_place_order(
    IN  p_customer_id INT,
    IN  p_name        VARCHAR(100),
    IN  p_phone       VARCHAR(20),
    IN  p_address     VARCHAR(255),
    IN  p_city        VARCHAR(100),
    IN  p_notes       TEXT,
    OUT p_order_id    INT,
    OUT p_error       VARCHAR(255)
)
proc_label: BEGIN
    DECLARE v_total  DECIMAL(10,2) DEFAULT 0;
    DECLARE v_pid    INT;
    DECLARE v_name   VARCHAR(200);
    DECLARE v_price  DECIMAL(10,2);
    DECLARE v_base   DECIMAL(10,2);
    DECLARE v_qty    INT;
    DECLARE v_disc   INT;
    DECLARE v_ref    VARCHAR(100);
    DECLARE v_stock  INT;
    DECLARE done     INT DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT c.product_id, p.name, c.locked_price, p.base_price, c.quantity
        FROM   cart c
        JOIN   products p ON c.product_id = p.id
        WHERE  c.customer_id     = p_customer_id
          AND  c.lock_expires_at > NOW();

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_order_id = 0;
        SET p_error    = 'Transaction failed — rolled back.';
    END;

    SET p_order_id = 0;
    SET p_error    = '';

    -- Validate delivery details
    IF p_address = '' OR p_phone = '' THEN
        SET p_error = 'Delivery address and phone are required.';
        LEAVE proc_label;
    END IF;

    -- Validate cart has active locks
    SELECT COALESCE(SUM(c.locked_price * c.quantity), 0) INTO v_total
    FROM   cart c
    WHERE  c.customer_id     = p_customer_id
      AND  c.lock_expires_at > NOW();

    IF v_total <= 0 THEN
        SET p_error = 'Cart is empty or all price locks have expired. Please re-add items.';
        LEAVE proc_label;
    END IF;

    START TRANSACTION;

    
    INSERT INTO orders (customer_id, total_amount, status,
                        delivery_name, delivery_phone, delivery_address, delivery_city, notes)
    VALUES (p_customer_id, v_total, 'pending',
            p_name, p_phone, p_address, p_city, p_notes);

    SET p_order_id = LAST_INSERT_ID();

  
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_pid, v_name, v_price, v_base, v_qty;
        IF done THEN LEAVE read_loop; END IF;

        SET v_disc = IF(v_base > 0, ROUND((1 - v_price / v_base) * 100), 0);

        INSERT INTO order_items
            (order_id, product_id, product_name, quantity, unit_price, discount_pct)
        VALUES
            (p_order_id, v_pid, v_name, v_qty, v_price, v_disc);

        -- Lock the stock row and verify quantity
        SELECT stock INTO v_stock FROM products WHERE id = v_pid FOR UPDATE;

        IF v_stock < v_qty THEN
            SET p_error = CONCAT('"', v_name, '" does not have enough stock.');
            CLOSE cur;
            ROLLBACK;
            SET p_order_id = 0;
            LEAVE proc_label;
        END IF;

        UPDATE products SET stock = stock - v_qty WHERE id = v_pid;
    END LOOP;
    CLOSE cur;

  
    SET v_ref = CONCAT('COD-', p_order_id, '-', UNIX_TIMESTAMP());
    INSERT INTO payments (order_id, amount, method, status, transaction_ref)
    VALUES (p_order_id, v_total, 'cod', 'pending', v_ref);

    
    INSERT INTO order_tracking (order_id, status, note, changed_by)
    VALUES (p_order_id, 'pending', 'Order placed — Cash on Delivery', p_customer_id);

    
    DELETE FROM cart WHERE customer_id = p_customer_id;

    COMMIT;
    SET p_error = '';
END$$


DROP PROCEDURE IF EXISTS sp_seller_cleanup_expired$$

CREATE PROCEDURE sp_seller_cleanup_expired(IN p_seller_id INT)
BEGIN
    UPDATE products
    SET    is_active = 0
    WHERE  seller_id  = p_seller_id
      AND  expires_at < NOW()
      AND  is_active   = 1;
    SELECT ROW_COUNT() AS deactivated_count;
END$$


DROP PROCEDURE IF EXISTS sp_admin_platform_report$$

CREATE PROCEDURE sp_admin_platform_report()
BEGIN
    SELECT
        (SELECT COALESCE(SUM(total_amount), 0)
         FROM orders WHERE status IN ('delivered','completed'))      AS total_revenue,
        (SELECT COUNT(*)
         FROM orders WHERE status IN ('delivered','completed'))      AS total_orders,
        (SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1) AS active_customers,
        (SELECT COUNT(*) FROM users WHERE role='seller'   AND is_active=1) AS active_sellers,
        (SELECT COUNT(*) FROM active_products)                            AS live_products,
        (SELECT COUNT(*) FROM products WHERE expires_at < NOW() AND is_active=1) AS expired_unsold;
END$$

DELIMITER ;

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- All demo accounts use password: password
INSERT INTO users (name, email, password, role, phone, address) VALUES
('Admin',         'admin@expiryrush.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',    '+92 300 0000001', '123 Admin Street, Karachi'),
('Fresh Mart',    'seller@expiryrush.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'seller',   '+92 300 0000002', '456 Market Avenue, Lahore'),
('John Doe',      'customer@expiryrush.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', '+92 300 0000003', '789 Customer Road, Karachi'),
('Sarah Johnson', 'sarah@example.com',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', '+92 300 0000004', '321 Buyer Lane, Islamabad'),
('Mike Wilson',   'mike@example.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', '+92 300 0000005', '654 Shopper Drive, Lahore');

INSERT INTO categories (name, description) VALUES
('Dairy',        'Milk, cheese, yogurt, and other dairy products'),
('Bakery',       'Bread, pastries, cakes, and baked goods'),
('Fruits',       'Fresh fruits including seasonal varieties'),
('Vegetables',   'Fresh vegetables and salad greens'),
('Meat',         'Chicken, beef, pork, and other meats'),
('Beverages',    'Juices, soft drinks, and other beverages'),
('Snacks',       'Chips, cookies, and quick bites'),
('Frozen Foods', 'Frozen vegetables, meals, and desserts');

INSERT INTO products (seller_id, category_id, name, description, base_price, stock, listed_at, expires_at) VALUES
(2, 2, 'Fresh Sourdough Bread',   'Artisan sourdough, baked fresh daily',          350.00, 10, NOW() - INTERVAL 22 HOUR, NOW() + INTERVAL 26 HOUR),
(2, 1, 'Organic Milk 1L',         'Fresh full cream milk, organic certified',       180.00, 15, NOW() - INTERVAL 10 HOUR, NOW() + INTERVAL 38 HOUR),
(2, 3, 'Fresh Strawberries 500g', 'Sweet local strawberries, freshly picked',       220.00,  8, NOW() - INTERVAL 42 HOUR, NOW() + INTERVAL  6 HOUR),
(2, 4, 'Garden Salad Mix',        'Mixed greens with tomatoes and cucumber',        150.00, 12, NOW() - INTERVAL 20 HOUR, NOW() + INTERVAL 28 HOUR),
(2, 5, 'Chicken Breast 1kg',      'Boneless chicken breast, halal certified',       680.00,  5, NOW() - INTERVAL 60 HOUR, NOW() + INTERVAL 12 HOUR),
(2, 2, 'Chocolate Croissants',    'Buttery croissants with dark chocolate filling', 320.00,  6, NOW() - INTERVAL 23 HOUR, NOW() + INTERVAL 25 HOUR),
(2, 1, 'Greek Yogurt 400g',       'Strained yogurt, high protein, low fat',         250.00, 20, NOW() - INTERVAL 40 HOUR, NOW() + INTERVAL 20 HOUR),
(2, 6, 'Fresh Orange Juice 1L',   'Cold pressed, no added sugar, pulp free',        280.00, 10, NOW() - INTERVAL 18 HOUR, NOW() + INTERVAL 30 HOUR),
(2, 3, 'Banana Bunch',            'Ripe yellow bananas, sweet and nutritious',      120.00, 18, NOW() - INTERVAL 12 HOUR, NOW() + INTERVAL 36 HOUR),
(2, 4, 'Cherry Tomatoes 250g',    'Sweet cherry tomatoes, perfect for salads',      180.00,  9, NOW() - INTERVAL 30 HOUR, NOW() + INTERVAL 18 HOUR),
(2, 1, 'Cheddar Cheese 200g',     'Aged cheddar, sharp and creamy',                 450.00,  7, NOW() - INTERVAL 15 HOUR, NOW() + INTERVAL 24 HOUR),
(2, 2, 'Whole Wheat Bread',       'Healthy whole wheat loaf, fiber rich',           280.00, 12, NOW() - INTERVAL  8 HOUR, NOW() + INTERVAL 40 HOUR),
(2, 7, 'Potato Chips 150g',       'Crunchy salted chips, perfect snack',             99.00, 25, NOW() - INTERVAL 50 HOUR, NOW() + INTERVAL 10 HOUR),
(2, 6, 'Green Tea 500ml',         'Cold brew green tea with honey',                 150.00, 14, NOW() - INTERVAL 25 HOUR, NOW() + INTERVAL 23 HOUR),
(2, 8, 'Mixed Vegetables 500g',   'Frozen peas, corn, and carrots mix',             190.00, 20, NOW() - INTERVAL 100 HOUR, NOW() + INTERVAL 44 HOUR);

INSERT INTO price_tiers (product_id, hours_before_expiry, discount_pct) VALUES
(1, 4, 50.00),(1, 2, 70.00),(1, 1, 85.00),
(2, 5, 30.00),(2, 3, 50.00),(2, 1, 65.00),
(3, 4, 40.00),(3, 2, 60.00),
(4, 3, 45.00),(4, 1, 70.00),
(5, 6, 35.00),(5, 3, 55.00),
(6, 2, 50.00),(6, 1, 75.00),
(8, 4, 40.00),(8, 2, 60.00);

-- Historical orders with delivery details and new status values
INSERT INTO orders (customer_id, total_amount, status, delivery_name, delivery_phone,
                    delivery_address, delivery_city, created_at) VALUES
(3, 530.00, 'delivered',  'John Doe',      '+92 300 0000003', '789 Customer Road', 'Karachi',    NOW() - INTERVAL 5 DAY),
(3, 680.00, 'delivered',  'John Doe',      '+92 300 0000003', '789 Customer Road', 'Karachi',    NOW() - INTERVAL 3 DAY),
(4, 350.00, 'delivered',  'Sarah Johnson', '+92 300 0000004', '321 Buyer Lane',    'Islamabad',  NOW() - INTERVAL 2 DAY),
(5, 820.00, 'processing', 'Mike Wilson',   '+92 300 0000005', '654 Shopper Drive', 'Lahore',     NOW() - INTERVAL 1 DAY),
(3, 450.00, 'cancelled',  'John Doe',      '+92 300 0000003', '789 Customer Road', 'Karachi',    NOW() - INTERVAL 4 DAY);

INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, discount_pct) VALUES
(1,  1, 'Fresh Sourdough Bread',   1, 280.00, 20),
(1,  2, 'Organic Milk 1L',         1, 162.00, 10),
(2,  5, 'Chicken Breast 1kg',      1, 510.00, 25),
(3,  3, 'Fresh Strawberries 500g', 1, 198.00, 10),
(3,  6, 'Chocolate Croissants',    1, 152.00,  5),
(4,  7, 'Greek Yogurt 400g',       2, 225.00, 10),
(4, 11, 'Cheddar Cheese 200g',     1, 370.00, 18),
(5,  4, 'Garden Salad Mix',        1, 150.00,  0),
(5,  9, 'Banana Bunch',            2, 150.00,  0);


INSERT INTO payments (order_id, amount, method, status, transaction_ref, paid_at) VALUES
(1, 530.00, 'cod', 'success', 'COD-001-DEMO', NOW() - INTERVAL 5 DAY),
(2, 680.00, 'cod', 'success', 'COD-002-DEMO', NOW() - INTERVAL 3 DAY),
(3, 350.00, 'cod', 'success', 'COD-003-DEMO', NOW() - INTERVAL 2 DAY),
(4, 820.00, 'cod', 'pending', 'COD-004-DEMO', NULL),
(5, 450.00, 'cod', 'failed',  'COD-005-DEMO', NULL);


INSERT INTO order_tracking (order_id, status, note, changed_by, changed_at) VALUES
(1, 'pending',          'Order placed — Cash on Delivery',      3, NOW() - INTERVAL 5 DAY),
(1, 'processing',       'Order packed and ready',               2, NOW() - INTERVAL 5 DAY + INTERVAL 2 HOUR),
(1, 'out_for_delivery', 'Out for delivery',                     2, NOW() - INTERVAL 5 DAY + INTERVAL 6 HOUR),
(1, 'delivered',        'Delivered and payment collected',      2, NOW() - INTERVAL 5 DAY + INTERVAL 10 HOUR),
(2, 'pending',          'Order placed — Cash on Delivery',      3, NOW() - INTERVAL 3 DAY),
(2, 'processing',       'Being packed',                         2, NOW() - INTERVAL 3 DAY + INTERVAL 1 HOUR),
(2, 'out_for_delivery', 'On the way',                           2, NOW() - INTERVAL 3 DAY + INTERVAL 5 HOUR),
(2, 'delivered',        'Delivered successfully',               2, NOW() - INTERVAL 3 DAY + INTERVAL 9 HOUR),
(3, 'pending',          'Order placed — Cash on Delivery',      4, NOW() - INTERVAL 2 DAY),
(3, 'processing',       'Packed',                               2, NOW() - INTERVAL 2 DAY + INTERVAL 2 HOUR),
(3, 'out_for_delivery', 'Out for delivery',                     2, NOW() - INTERVAL 2 DAY + INTERVAL 5 HOUR),
(3, 'delivered',        'Delivered',                            2, NOW() - INTERVAL 2 DAY + INTERVAL 8 HOUR),
(4, 'pending',          'Order placed — Cash on Delivery',      5, NOW() - INTERVAL 1 DAY),
(4, 'processing',       'Being prepared',                       2, NOW() - INTERVAL 1 DAY + INTERVAL 3 HOUR),
(5, 'pending',          'Order placed — Cash on Delivery',      3, NOW() - INTERVAL 4 DAY),
(5, 'cancelled',        'Cancelled by customer',                3, NOW() - INTERVAL 4 DAY + INTERVAL 1 HOUR);

-- Rush alerts
INSERT INTO rush_alerts (user_id, product_id, target_discount, triggered) VALUES
(3, 2, 30, 0),
(4, 5, 40, 0),
(5, 3, 35, 1);


SELECT 'Database Setup Complete — ExpiryRush v3 (COD)' AS Status;
SELECT COUNT(*) AS TotalUsers         FROM users;
SELECT COUNT(*) AS TotalCategories    FROM categories;
SELECT COUNT(*) AS TotalProducts      FROM products;
SELECT COUNT(*) AS ActiveProducts     FROM active_products;
SELECT COUNT(*) AS TotalOrders        FROM orders;
SELECT COUNT(*) AS TrackingEntries    FROM order_tracking;

SELECT name,
       base_price,
       ROUND(current_price, 0)      AS current_price,
       discount_percent              AS disc_pct,
       ROUND(seconds_left / 3600, 1) AS hours_left
FROM   active_products
ORDER  BY discount_percent DESC
LIMIT  10;

CALL sp_admin_platform_report();
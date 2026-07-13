USE expiry_rush;

UPDATE users
SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE email IN (
    'admin@expiryrush.com',
    'seller@expiryrush.com',
    'customer@expiryrush.com'
);

SELECT id, name, email, role, is_active FROM users;

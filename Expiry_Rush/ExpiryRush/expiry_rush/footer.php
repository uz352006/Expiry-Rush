</main>
<footer class="site-footer">
    <p>⚡ <strong>ExpiryRush</strong> — Beat the Clock. Save the Food. © <?= date('Y') ?></p>
</footer>
<script>
function updateCartCount() {
    const badge = document.getElementById('cartCount');
    if (!badge) return;
    fetch('<?= BASE_URL ?>cart_count.php')
        .then(response => response.json())
        .then(data => {
            if (data.count !== undefined) {
                const oldCount = parseInt(badge.textContent);
                badge.textContent = data.count;
                if (oldCount !== data.count && data.count > 0) {
                    badge.classList.add('cart-count-update');
                    setTimeout(() => badge.classList.remove('cart-count-update'), 300);
                }
                badge.style.opacity = data.count === 0 ? '0.6' : '1';
            }
        })
        .catch(error => console.log('Cart update error:', error));
}
if (document.getElementById('cartCount')) {
    updateCartCount();
    setInterval(updateCartCount, 5000);
}
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && document.getElementById('cartCount')) {
        updateCartCount();
    }
});
</script>
</body>
</html>
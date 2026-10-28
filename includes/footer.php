    </div>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <span><?= h(site_text('footer_text')) ?></span>
        <?php if (site_text('footer_support') !== ''): ?>
            <span class="footer-support"><?= h(site_text('footer_support')) ?></span>
        <?php endif; ?>
    </div>
</footer>
</body>
</html>

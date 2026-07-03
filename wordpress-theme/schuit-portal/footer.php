<?php
declare(strict_types=1);
?>
<footer class="site-footer">
  <div class="site-footer__inner">
    <div>
      <strong><?php echo esc_html(get_bloginfo('name')); ?></strong><br>
      <span>Voor hulp bij het zoeken kunt u contact opnemen met Jan Schuit via email jan@schuit.info.</span>
    </div>
    <div>
      <a href="<?php echo esc_url(home_url('/tree/')); ?>">Naar webtrees</a>
    </div>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>

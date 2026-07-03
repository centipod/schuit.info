<?php
declare(strict_types=1);
?>
<footer class="site-footer">
  <div class="site-footer__inner">
    <div class="site-footer__brand">
      <a class="site-brand site-brand--footer" href="<?php echo esc_url(home_url('/')); ?>">
        <img class="site-brand__mark" src="<?php echo esc_url(home_url('/logo.png')); ?>" alt="Schuit logo">
        <span class="site-brand__text">
          <span class="site-brand__title"><?php echo esc_html(get_bloginfo('name')); ?></span>
          <span class="site-brand__subtitle">Familiearchief</span>
        </span>
      </a>
      <p>Archief, verhalen en genealogie van de Schuit-familie, met webtrees als centrale ingang.</p>
    </div>
    <div class="site-footer__column">
      <h2>Navigation</h2>
      <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
      <a href="<?php echo esc_url(home_url('/?post_type=post')); ?>">Nieuws</a>
      <a href="<?php echo esc_url(home_url('/verhalen/')); ?>">Verhalen</a>
      <a href="<?php echo esc_url(home_url('/tree/')); ?>">Stamboom</a>
    </div>
    <div class="site-footer__column">
      <h2>Resources</h2>
      <a href="<?php echo esc_url(home_url('/schuit/?cat=5')); ?>">Publicaties</a>
      <a href="<?php echo esc_url(home_url('/archief/')); ?>">Archief</a>
      <a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact</a>
      <a href="<?php echo esc_url(home_url('/tree/')); ?>">Open webtrees</a>
    </div>
    <div class="site-footer__column site-footer__column--contact">
      <h2>Contact</h2>
      <p>Vragen of verhalen om te delen? Mail jan@schuit.info.</p>
      <a class="button button--primary site-footer__cta" href="mailto:jan@schuit.info">Get in touch</a>
    </div>
  </div>
  <div class="site-footer__legal">
    <span>&copy; <?php echo esc_html(date('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?>.</span>
    <a href="<?php echo esc_url(home_url('/tree/')); ?>">Proudly preserving family history.</a>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>

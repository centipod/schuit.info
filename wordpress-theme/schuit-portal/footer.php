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
      <p>Publicaties en genealogie van Schuyt, Schuit, Schuijt en verwante namen.</p>
    </div>
    <div class="site-footer__column">
      <h2>Navigatie</h2>
      <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
      <a href="<?php echo esc_url(schuit_portal_category_url('publicaties', 5)); ?>">Publicaties</a>
      <a href="<?php echo esc_url(home_url('/tree/')); ?>">Webtrees</a>
      <a href="https://www.facebook.com/stichting.schuyiijt" target="_blank" rel="noopener">Facebook</a>
    </div>
    <div class="site-footer__column">
      <h2>De stichting</h2>
      <?php foreach (['about' => 'Over de stichting', 'bestuur' => 'Bestuur', 'geslachten' => 'Geslachten', 'privacyreglement' => 'Privacyreglement', 'algemene-voorwaarden' => 'Algemene voorwaarden', 'general-information' => 'General information'] as $slug => $label) : ?>
        <a href="<?php echo esc_url(schuit_portal_page_url($slug, '/')); ?>"><?php echo esc_html($label); ?></a>
      <?php endforeach; ?>
    </div>
    <div class="site-footer__column site-footer__column--contact">
      <h2>Contact</h2>
      <p>Vragen of verhalen om te delen? Mail jan@schuit.info.</p>
      <a class="button button--primary site-footer__cta" href="<?php echo esc_url(schuit_portal_page_url('contact', '/contact/')); ?>">Neem contact op</a>
    </div>
  </div>
  <div class="site-footer__legal">
    <span>&copy; <?php echo esc_html(date('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?>.</span>
    <a href="<?php echo esc_url(home_url('/tree/')); ?>">Familiegeschiedenis zorgvuldig bewaard.</a>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>

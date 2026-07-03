<?php
declare(strict_types=1);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-brand" href="<?php echo esc_url(home_url('/')); ?>">
      <img class="site-brand__mark" src="<?php echo esc_url(home_url('/logo.png')); ?>" alt="Schuit logo">
      <span class="site-brand__text">
        <span class="site-brand__title"><?php echo esc_html(get_bloginfo('name')); ?></span>
      </span>
    </a>
    <nav class="site-nav" aria-label="Hoofdmenu">
      <?php
      wp_nav_menu([
          'theme_location' => 'primary',
          'container' => false,
          'fallback_cb' => 'schuit_portal_fallback_menu',
      ]);
      ?>
    </nav>
  </div>
</header>

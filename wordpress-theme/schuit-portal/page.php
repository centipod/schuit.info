<?php
declare(strict_types=1);
get_header();
?>
<main class="site-main schuit-shell">
  <?php while (have_posts()) : the_post(); ?>
    <?php $fallback = schuit_portal_fallback_content(get_post_field('post_name', get_the_ID())); ?>
    <article <?php post_class('page'); ?>>
      <header class="entry-header">
        <h1 class="entry-title"><?php echo esc_html($fallback['title'] ?: get_the_title()); ?></h1>
        <?php if (has_excerpt()) : ?><p class="meta"><?php the_excerpt(); ?></p><?php endif; ?>
      </header>
      <div class="entry-content">
        <?php
        $content = trim(wp_strip_all_tags(get_the_content('', false, get_the_ID())));
        if ($content !== '') {
            the_content();
        } elseif ($fallback['content'] !== '') {
            echo wp_kses_post($fallback['content']);
        } else {
            the_content();
        }
        ?>
        <?php if (get_post_field('post_name', get_the_ID()) === 'about') : ?>
          <nav class="foundation-links" aria-label="Meer over de stichting"><h2>De stichting en ons onderzoek</h2><ul>
            <?php foreach (schuit_portal_foundation_links() as $label => $slug) : ?>
              <li><a href="<?php echo esc_url(schuit_portal_page_url($slug, '/')); ?>"><?php echo esc_html($label); ?></a></li>
            <?php endforeach; ?>
          </ul></nav>
        <?php endif; ?>
      </div>
    </article>
  <?php endwhile; ?>
</main>
<?php get_footer(); ?>

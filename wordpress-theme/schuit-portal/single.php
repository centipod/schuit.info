<?php
declare(strict_types=1);
get_header();
?>
<main class="site-main schuit-shell">
  <?php while (have_posts()) : the_post(); ?>
    <article <?php post_class('post'); ?>>
      <header class="entry-header">
        <p class="meta"><?php echo esc_html(get_the_date()); ?></p>
        <h1 class="entry-title"><?php the_title(); ?></h1>
      </header>
      <div class="entry-content">
        <?php the_content(); ?>
      </div>
    </article>
  <?php endwhile; ?>
</main>
<?php get_footer(); ?>

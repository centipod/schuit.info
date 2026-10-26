<?php
// Run using wp eval with require, so inherited/private attachment metadata is
// included in the audit without changing visibility or exporting post bodies.
$items=[];
foreach(get_posts(['post_type'=>'attachment','post_status'=>['inherit','publish','private','draft','trash'],'posts_per_page'=>-1]) as $post){
 $file=(string)get_post_meta($post->ID,'_wp_attached_file',true);
 if($file===''||str_contains($file,'..')){continue;}
 $base='https://www.schuit.info/schuit/schuit/wp-content/uploads/';
 $urls=[$base.$file];$meta=wp_get_attachment_metadata($post->ID);
 foreach(($meta['sizes']??[]) as $size){if(isset($size['file'])){$urls[]=$base.dirname($file).'/'.$size['file'];}}
 $items[]=['id'=>$post->ID,'urls'=>$urls];
}
echo json_encode($items,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";

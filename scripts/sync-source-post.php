<?php
// Only the one public article whose source text changed since the first migration.
$source=json_decode(file_get_contents('/tmp/schuit-source-post.json'),true,512,JSON_THROW_ON_ERROR);
if($source['id']!==180095 || $source['slug']!=='bijeenkomst-katwijk-aan-zee' || get_post_type(180095)!=='post'){throw new RuntimeException('Unexpected source article');}
$content=$source['content']['rendered'];
foreach(['https','http'] as $scheme){
 foreach(['www.schuit.info','schuit.info'] as $host){
  foreach(['/schuit/schuit','/schuit'] as $prefix){$content=str_replace($scheme.'://'.$host.$prefix,home_url(''),$content);}
 }
}
$id=wp_update_post(['ID'=>180095,'post_content'=>wp_slash($content)],true);
if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}
echo "Updated the archived Katwijk article from its original source.\n";

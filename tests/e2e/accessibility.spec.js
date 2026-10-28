const {test,expect}=require('@playwright/test');
test('portal navigation is keyboard reachable with visible focus and readable base colours',async({page})=>{
 await page.goto('/');
 let visited=false;
 for(let i=0;i<20;i++){
  await page.keyboard.press('Tab');
  const focused=page.locator(':focus');
  if(await focused.count() && await focused.evaluate(el=>el.closest('nav')!==null)){
   await expect(focused).toBeVisible();
   const outline=await focused.evaluate(el=>parseFloat(getComputedStyle(el).outlineWidth));
   expect(outline).toBeGreaterThanOrEqual(2);visited=true;break;
  }
 }
 expect(visited).toBe(true);
 const colours=await page.evaluate(()=>{const s=getComputedStyle(document.documentElement);return ['--schuit-ink','--schuit-muted','--schuit-accent-text','--schuit-bg'].map(v=>s.getPropertyValue(v).trim());});
 const luminance=hex=>{const c=hex.slice(1).match(/../g).map(x=>parseInt(x,16)/255).map(x=>x<=0.04045?x/12.92:((x+0.055)/1.055)**2.4);return c[0]*0.2126+c[1]*0.7152+c[2]*0.0722;};
 const bg=luminance(colours[3]);for(const value of colours.slice(0,3)){const fg=luminance(value);expect((Math.max(bg,fg)+0.05)/(Math.min(bg,fg)+0.05)).toBeGreaterThanOrEqual(4.5);}
});

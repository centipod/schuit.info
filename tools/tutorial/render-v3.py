#!/usr/bin/env python3
"""Compose an updated tutorial from verified real screen footage and Runway narration.

No browser automation or production access. Media and credentials stay outside Git.
"""
import argparse,json,math,re,subprocess,textwrap,wave
from pathlib import Path
from PIL import Image,ImageDraw,ImageFont,ImageOps

p=argparse.ArgumentParser()
p.add_argument('--previous-video',type=Path,required=True)
p.add_argument('--media-dir',type=Path,required=True)
p.add_argument('--output-dir',type=Path,required=True)
a=p.parse_args();work=a.media_dir.resolve();out=a.output_dir.resolve();out.mkdir(parents=True,exist_ok=True)
build=work/'render';build.mkdir(exist_ok=True)
manifest=json.loads((work/'audio-final.json').read_text())
entries={x['scene']:x for x in manifest}
fonts=Path('/System/Library/Fonts/Supplemental')
def font(size,bold=False):return ImageFont.truetype(str(fonts/('Arial Bold.ttf' if bold else 'Arial.ttf')),size)
def run(args):subprocess.run(args,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
def duration(f):return float(subprocess.check_output(['ffprobe','-v','error','-show_entries','format=duration','-of','default=nw=1:nk=1',str(f)]))
def stamp(t):
 ms=round(t*1000);return f'{ms//3600000:02}:{ms//60000%60:02}:{ms//1000%60:02},{ms%1000:03}'
def card(name,title,lines,footer):
 im=Image.new('RGB',(1440,810),'#f6f3ed');d=ImageDraw.Draw(im)
 d.rounded_rectangle((45,45,1395,765),radius=22,fill='white',outline='#d6ddd4',width=2)
 d.text((100,100),title,font=font(46,True),fill='#153f3a')
 for i,(lead,detail) in enumerate(lines):
  y=225+i*125;d.ellipse((100,y,150,y+50),fill='#153f3a');d.text((125,y+25),str(i+1),anchor='mm',font=font(27,True),fill='white')
  d.text((180,y-4),lead,font=font(32,True),fill='#153f3a');d.text((180,y+48),detail,font=font(26),fill='#52655c')
 d.text((100,700),footer,font=font(24,True),fill='#8b682f');f=build/(name+'.png');im.save(f);return f
aldfaer=card('aldfaer','Van Aldfaer naar een geschikt GEDCOM-bestand',[
 ('Exporteer vanuit Aldfaer','Menu Bestand → GEDCOM-export'),
 ('Kies GEDCOM 5.5 of 5.5.1','Bewaar het exportbestand met de extensie .ged'),
 ('Upload het .ged-bestand in GEDCOM-beheer','Een bestaand geschikt GEDCOM-bestand kunt u direct gebruiken.')],
 'Niet rechtstreeks ondersteund: Aldfaer .alx en GEDCOM 7')
end=card('end','Klaar? Controleer en bewaar.',[
 ('Vergelijk personen en gezinnen','Controleer ook enkele personen en gezinsrelaties.'),
 ('Bewaar uw oorspronkelijke bestand','Foto’s en andere media worden niet met deze upload toegevoegd.'),
 ('Bij twijfel: stop en vraag hulp','Importeer niet opnieuw voordat u de melding begrijpt.')],
 'Aanmelden: https://schuit.info/wp-login.php')
titles=['Stamboomgegevens bijwerken','1 · Een bestand uit Aldfaer voorbereiden','2 · Aanmelden en GEDCOM-beheer openen','3 · GEDCOM uploaden','4 · Het controlerapport bekijken','5 · Bevestigen en verwerken','6 · De statistieken in webtrees controleren','Bewaren en afronden']
subtitles=['Aldfaer → GEDCOM → controle → webtrees','Heeft u al een GEDCOM-bestand? Ga door naar aanmelden.','https://schuit.info/wp-login.php','Uploaden verandert de bestaande stamboom nog niet.','Controleer personen, gezinnen, versie en waarschuwingen.','De bestaande genealogische gegevens worden vervangen.','Verwacht in deze demonstratie: 12 personen en 4 gezinnen.','https://schuit.info/wp-login.php']
names=['intro','aldfaer','login','upload','review','import','stats','end']
# These are real recordings of the same plugin workflow, revalidated in the
# current synthetic fixture. Fresh screenshots document navigation and final counts.
visuals={
 'intro':[(2,14,1)],'aldfaer':[(aldfaer,None,1)],
 'login':[(25,33,.45),(work/'captures/tools.png',None,.20),(35,41,.35)],
 'upload':[(41,62,1)],'review':[(62,94,1)],'import':[(94,119,1)],
 'stats':[(119,133,.70),(work/'captures/statistics.png',None,.30)],'end':[(end,None,1)]}
logo=Image.open(Path(__file__).resolve().parents[2]/'logo.png').convert('RGBA');logo.thumbnail((65,65))
timeline=[];srt=[];clock=0
for idx,name in enumerate(names):
 audiofile=work/'audio'/(name+'.mp3')
 if name=='stats':
  # Keep the product name in English; retain natural Dutch for the explanation.
  raw=[]
  for f in [work/'audio/webtrees.mp3',audiofile]:
   raw.append(subprocess.check_output(['ffmpeg','-v','error','-i',str(f),'-f','s16le','-ac','1','-ar','48000','-']))
  audiofile=build/'stats.wav'
  with wave.open(str(audiofile),'wb') as w:w.setnchannels(1);w.setsampwidth(2);w.setframerate(48000);w.writeframes(raw[0]+bytes(28800)+raw[1])
 speech=duration(audiofile);length=math.ceil((speech+1.25)*25)/25
 text=entries[name]['text'];text=('Webtrees. '+text) if name=='stats' else text
 # Anchor sentence changes near real pauses in the voiceover.
 log=subprocess.run(['ffmpeg','-hide_banner','-i',str(audiofile),'-af','silencedetect=noise=-35dB:d=0.15','-f','null','-'],capture_output=True,text=True,check=True).stderr
 pauses=[];silence=None
 for line in log.splitlines():
  if 'silence_start:' in line:silence=float(line.split('silence_start:')[1].split()[0])
  if 'silence_end:' in line and silence is not None:
   stop=float(line.split('silence_end:')[1].split()[0])
   if silence>.1 and stop<speech-.1:pauses.append((silence+stop)/2)
   silence=None
 sentences=re.split(r'(?<=[.!?])\s+',text);weights=[len(x) for x in sentences];bounds=[0];cumulative=0
 for w in weights[:-1]:
  cumulative+=w;target=speech*cumulative/sum(weights)
  options=[x for x in pauses if x>bounds[-1]+.7 and abs(x-target)<1.3]
  bounds.append(min(options,key=lambda x:abs(x-target)) if options else target)
 bounds.append(speech);captions=[]
 for j,sentence in enumerate(sentences):
  chunks=textwrap.wrap(sentence,width=124,break_long_words=False);pos=bounds[j]+.35
  for chunk in chunks:
   endtime=pos+(bounds[j+1]-bounds[j])*len(chunk)/sum(map(len,chunks));captions.append((pos,endtime,chunk));srt.append((clock+pos,clock+endtime,chunk));pos=endtime
 points=sorted(set([0,length]+[v for x,y,_ in captions for v in [x,y]]));overlays=[]
 for k,(start,stop) in enumerate(zip(points,points[1:])):
  im=Image.new('RGBA',(1600,1000),(246,243,237,255));d=ImageDraw.Draw(im)
  d.rectangle((79,99,1521,911),fill='#ced6ce');d.rectangle((80,100,1519,909),fill=(0,0,0,0));im.alpha_composite(logo,(80,15))
  d.text((162,15),titles[idx],font=font(28,True),fill='#153f3a')
  d.text((162,55),subtitles[idx],font=font(26,idx in [2,7]),fill='#153f3a' if idx in [2,7] else '#725b41')
  d.rounded_rectangle((1160,16,1520,45),radius=14,fill='#e7e9e2');d.text((1340,30),'DEMONSTRATIE · FICTIEVE GEGEVENS',font=font(14,True),anchor='mm',fill='#36554c')
  d.rectangle((80,91,1520,94),fill='#d7dcd3');d.rectangle((80,91,80+1440*(idx+1)/8,94),fill='#b28b4e')
  cap=next((v for x,y,v in captions if x<=(start+stop)/2<y),'')
  lines=textwrap.wrap(cap,width=100)
  for n,line in enumerate(lines):d.text((800,948+(n-(len(lines)-1)/2)*30),line,font=font(25),anchor='mm',fill='#173f3a')
  f=build/f'{name}-overlay-{k}.png';im.save(f);overlays.append(f"file '{f}'\nduration {stop-start:.6f}\n")
 overlays.append(f"file '{f}'\n");overlaylist=build/f'{name}-overlays.txt';overlaylist.write_text(''.join(overlays))
 pieces=[]
 for k,(src,endtime,weight) in enumerate(visuals[name]):
  dur=length*weight;dest=build/f'{name}-part-{k}.mp4'
  if endtime is not None:
   inputs=['-ss',str(src),'-t',str(endtime-src),'-i',str(a.previous_video)]
   vf=f'crop=1440:810:80:100,setpts={dur/(endtime-src)}*(PTS-STARTPTS),fps=25,pad=1600:1000:80:100:color=0xf6f3ed'
  else:
   inputs=['-loop','1','-i',str(src)]
   vf='scale=1440:810:force_original_aspect_ratio=decrease,pad=1440:810:(ow-iw)/2:(oh-ih)/2:color=0xf6f3ed,pad=1600:1000:80:100:color=0xf6f3ed,fps=25'
  # Identical sample aspect ratios prevent filter reinitialization (and loss of
  # the overlay) when concatenating a screenshot with recorded video.
  run(['ffmpeg','-v','error','-y',*inputs,'-vf',vf+',setsar=1','-t',str(dur),'-an','-c:v','libx264','-preset','fast','-crf','18','-pix_fmt','yuv420p',str(dest)]);pieces.append(f"file '{dest}'\n")
 partlist=build/f'{name}-parts.txt';partlist.write_text(''.join(pieces));segment=build/f'{idx:02}-{name}.mp4'
 run(['ffmpeg','-v','error','-y','-f','concat','-safe','0','-i',str(partlist),'-f','concat','-safe','0','-i',str(overlaylist),'-i',str(audiofile),'-filter_complex','[0:v][1:v]overlay=0:0:format=auto,fps=25,format=yuv420p[v];[2:a]adelay=350:all=1,apad[a]','-map','[v]','-map','[a]','-t',str(length),'-c:v','libx264','-preset','fast','-crf','18','-c:a','aac','-b:a','192k','-ar','48000',str(segment)])
 timeline.append({'scene':name,'start':clock,'duration':length,'file':str(segment),'title':titles[idx]});clock+=length
 print('Rendered',name,round(length,2),flush=True)
concat=build/'segments.txt';concat.write_text(''.join(f"file '{x['file']}'\n" for x in timeline))
final=out/'SCHUIT-Aldfaer-GEDCOM-handleiding-NL-2026-10-29.mp4'
run(['ffmpeg','-v','error','-y','-f','concat','-safe','0','-i',str(concat),'-c:v','copy','-af','loudnorm=I=-16:TP=-1.5:LRA=7','-c:a','aac','-b:a','192k','-ar','48000','-movflags','+faststart',str(final)])
(out/'SCHUIT-Aldfaer-GEDCOM-handleiding-NL.srt').write_text('\n\n'.join(f'{i+1}\n{stamp(x)} --> {stamp(y)}\n{v}' for i,(x,y,v) in enumerate(srt))+'\n')
(out/'chapters.json').write_text(json.dumps([{k:v for k,v in x.items() if k!='file'} for x in timeline],ensure_ascii=False,indent=2))
print(final,flush=True)

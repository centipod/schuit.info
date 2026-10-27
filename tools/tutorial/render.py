#!/usr/bin/env python3
"""Offline video composition; never connects to or modifies the live website."""
from pathlib import Path
from PIL import Image,ImageDraw,ImageFont
import argparse,json,subprocess,wave,math,textwrap,re

parser=argparse.ArgumentParser(description="Render the Dutch SCHUIT tutorial from a real screen recording and premium narration. Media inputs are local and never committed.")
parser.add_argument('--recording-dir',type=Path,required=True,help='Contains timeline.json, raw-path.txt and html/logo.png from the synthetic recording')
parser.add_argument('--audio-dir',type=Path,required=True,help='Contains manifest.json and the corrected narration clips')
parser.add_argument('--output-dir',type=Path,required=True)
args=parser.parse_args()
p=args.recording_dir.resolve()
work=args.audio_dir.resolve()
out=args.output_dir.resolve()
out.mkdir(parents=True,exist_ok=True)
source=json.loads((p/'timeline.json').read_text())
manifest=json.loads((work/'manifest.json').read_text())
scenes=[]; captions=[]; cursor=1.0
for i,item in enumerate(manifest):
    s=dict(source[i]); s['start']=cursor; s['duration']=item['duration']+1.3
    s['end']=s['start']+s['duration'];s['file']=item['file'];s['text']=item['text']
    # Sentence boundaries are anchored to nearby pauses in the generated speech.
    probe=subprocess.run(['ffmpeg','-hide_banner','-i',item['file'],'-af','silencedetect=noise=-35dB:d=0.15','-f','null','-'],capture_output=True,text=True,check=True).stderr
    pauses=[];silence=None
    for line in probe.splitlines():
        if 'silence_start:' in line:silence=float(line.split('silence_start:')[1].split()[0])
        if 'silence_end:' in line and silence is not None:
            end=float(line.split('silence_end:')[1].split()[0])
            if silence>.1 and end<item['duration']-.1:pauses.append((silence+end)/2)
            silence=None
    sentences=re.split(r'(?<=[.!?])\s+',item['text'])
    weights=[len(x) for x in sentences]; total=sum(weights); bounds=[0.0]; cumulative=0
    for j,weight in enumerate(weights[:-1]):
        cumulative+=weight;target=item['duration']*cumulative/total
        options=[x for x in pauses if x>bounds[-1]+.7 and abs(x-target)<1.3]
        boundary=min(options,key=lambda x:abs(x-target)) if options else target
        bounds.append(max(bounds[-1]+.3,boundary))
    bounds.append(item['duration'])
    for j,sentence in enumerate(sentences):
        chunks=textwrap.wrap(sentence,width=112,break_long_words=False,break_on_hyphens=False)
        t=bounds[j];length=sum(len(x) for x in chunks)
        for chunk in chunks:
            end=t+(bounds[j+1]-bounds[j])*len(chunk)/length
            captions.append((cursor+.45+t,cursor+.45+end,chunk));t=end
    scenes.append(s);cursor=s['end']
duration=math.ceil((cursor+.5)*25)/25
(work/'timeline.json').write_text(json.dumps(scenes,ensure_ascii=False,indent=2))
rate=48000;audio=bytearray(math.ceil(duration*rate)*2)
for s in scenes:
    raw=subprocess.check_output(['ffmpeg','-v','error','-i',s['file'],'-f','s16le','-ac','1','-ar',str(rate),'-'])
    pos=round((s['start']+.45)*rate)*2;audio[pos:pos+len(raw)]=raw
with wave.open(str(work/'narration.wav'),'wb') as w:
    w.setnchannels(1);w.setsampwidth(2);w.setframerate(rate);w.writeframes(audio)
def stamp(t):
    ms=round(t*1000);return f'{ms//3600000:02}:{ms//60000%60:02}:{ms//1000%60:02},{ms%1000:03}'
(out/'GEDCOM-handleiding-NL-premium-v2.srt').write_text('\n\n'.join(f'{i+1}\n{stamp(a)} --> {stamp(b)}\n{t}' for i,(a,b,t) in enumerate(captions))+'\n')
F='/System/Library/Fonts/Supplemental/'
font=ImageFont.truetype(F+'Arial.ttf',24);titlefont=ImageFont.truetype(F+'Arial Bold.ttf',30);small=ImageFont.truetype(F+'Arial.ttf',19);badge=ImageFont.truetype(F+'Arial Bold.ttf',15)
urlfont=ImageFont.truetype(F+'Arial Bold.ttf',30)
calloutfont=ImageFont.truetype(F+'Arial Bold.ttf',38)
LOGIN_URL='https://schuit.centipod.ai/wp-login.php'
frames=work/'overlays';frames.mkdir(exist_ok=True);seq=[]
logo=Image.open(p/'html/logo.png').convert('RGBA');logo.thumbnail((65,65))
bounds=sorted(set([0,duration]+[s['start'] for s in scenes]+[s['end'] for s in scenes]+[t for c in captions for t in c[:2]]))
for i,(a,b) in enumerate(zip(bounds,bounds[1:])):
    if b-a<.001:continue
    t=(a+b)/2;s=next((s for s in scenes if s['start']<=t<s['end']),scenes[0] if t<scenes[0]['start'] else scenes[-1]);caption=next((v for x,y,v in captions if x<=t<y),'')
    im=Image.new('RGBA',(1600,1000),(246,243,237,255));d=ImageDraw.Draw(im)
    d.rectangle((79,99,1521,911),fill=(204,211,205,255));d.rectangle((80,100,1519,909),fill=(0,0,0,0));im.alpha_composite(logo,(80,15))
    d.text((162,15),s['title'],font=titlefont,fill='#153f3a')
    chapter=scenes.index(s)
    if chapter in (0,1,2,6):
        d.text((163,52),LOGIN_URL,font=urlfont,fill='#153f3a')
    else:
        d.text((163,55),s['subtitle'],font=small,fill='#725b41')
    if chapter==1:
        d.rounded_rectangle((220,112,1380,187),radius=12,fill='#ffffff',outline='#153f3a',width=3)
        d.text((800,148),LOGIN_URL,font=calloutfont,anchor='mm',fill='#153f3a')
    d.rounded_rectangle((1120,20,1520,52),radius=16,fill='#e7e9e2');d.text((1320,36),'DEMONSTRATIE · FICTIEVE GEGEVENS',font=badge,anchor='mm',fill='#36554c')
    lines=textwrap.wrap(caption,width=105)
    for n,line in enumerate(lines):d.text((800,948+(n-(len(lines)-1)/2)*30),line,font=font,anchor='mm',fill='#173f3a')
    progress=(scenes.index(s)+1)/len(scenes);d.rectangle((80,91,1520,94),fill='#d7dcd3');d.rectangle((80,91,80+1440*progress,94),fill='#b28b4e')
    f=frames/f'{i:03}.png';im.save(f);seq.append(f"file '{f}'\nduration {b-a:.6f}\n")
seq.append(f"file '{f}'\n");(work/'overlay-list.txt').write_text(''.join(seq))
# Each chapter is retimed independently, leaving the new voice at its natural speed.
raw=(p/'raw-path.txt').read_text().strip()
filters=['[0:v]split=2[va][vb]','[va]trim=end=98,setpts=PTS-STARTPTS[ca]','[vb]trim=start=110,setpts=PTS-STARTPTS[cb]','[ca][cb]concat=n=2:v=1:a=0[edited]','[edited]split=7'+''.join(f'[src{i}]' for i in range(7))]
starts=[0]+[s['start'] for s in source[1:]]
ends=starts[1:]+[111.767]
for i,s in enumerate(scenes):
    length=s['duration']+(1 if i==0 else 0)+(.5 if i==6 else 0)
    factor=length/(ends[i]-starts[i])
    filters.append(f'[src{i}]trim=start={starts[i]}:end={ends[i]},setpts=(PTS-STARTPTS)*{factor:.9f},fps=25,tpad=stop_mode=clone:stop_duration=0.08,trim=duration={length:.6f},setpts=PTS-STARTPTS[v{i}]')
filters.append(''.join(f'[v{i}]' for i in range(7))+'concat=n=7:v=1:a=0,pad=1600:1000:80:100:color=0xf6f3ed,tpad=stop_mode=clone:stop_duration=0.2[base]')
filters.extend(['[1:v]fps=25[frame]','[base][frame]overlay=0:0:shortest=1,format=yuv420p[v]','[2:a]loudnorm=I=-16:TP=-1.5:LRA=7[a]'])
(work/'filter.txt').write_text(';'.join(filters))
dest=out/'SCHUIT-GEDCOM-handleiding-NL-premium-v2.mp4'
cmd=['ffmpeg','-y','-v','warning','-i',raw,'-f','concat','-safe','0','-i',str(work/'overlay-list.txt'),'-i',str(work/'narration.wav'),'-filter_complex_script',str(work/'filter.txt'),'-map','[v]','-map','[a]','-c:v','libx264','-preset','fast','-crf','20','-c:a','aac','-ar','48000','-b:a','160k','-movflags','+faststart','-t',str(duration),str(dest)]
subprocess.run(cmd,check=True)
chapters='\n'.join(f"{int(s['start'])//60:02}:{int(s['start'])%60:02} {s['title']}" for s in scenes)
(out/'Lees-mij-premium-v2.txt').write_text(f'''SCHUIT — GEDCOM bijwerken\n\nNieuwe versie met Nederlandse premium AI-voice-over (Miriam via Runway).\nDe oorspronkelijke versie is apart bewaard.\nDe login-URL staat extra groot in beeld. De productnaam webtrees wordt in het Engels uitgesproken.\nDe GEDCOM-upload omvat geen foto’s of documenten; mediabeheer is een afzonderlijke stap.\nDuur: {int(duration)//60}:{int(duration)%60:02}.\n\n{chapters}\n\nEchte schermopname van een lokale kopie van WordPress en Webtrees.\nAlle accounts en familiegegevens zijn fictief. De echte stamboom is niet gewijzigd.\nDe import is werkelijk uitgevoerd: van 9 personen / 3 gezinnen naar 12 personen / 4 gezinnen.\nWachttijd is ingekort; het beeldtempo is aangepast aan de nieuwe voice-over.\nGebruik uw eigen account met toegang tot GEDCOM-beheer.\nLogin: https://schuit.centipod.ai/wp-login.php\n\nDe MP4 heeft ingebakken ondertitels. De SRT is ook afzonderlijk beschikbaar.\n''')
print(json.dumps({'output':str(dest),'duration':duration,'chapters':chapters},ensure_ascii=False))

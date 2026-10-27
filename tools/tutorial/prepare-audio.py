#!/usr/bin/env python3
"""Assemble existing narration with corrected Dutch/English premium voice clips.

Inputs: --recording-dir/premium/manifest.json plus five MP3 clips in --audio-dir,
named after narration-corrections.json. Generated assets stay outside Git.
"""
import argparse
import json
from pathlib import Path
import subprocess
import wave

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--recording-dir', type=Path, required=True)
parser.add_argument('--audio-dir', type=Path, required=True)
args = parser.parse_args()
work = args.audio_dir.resolve()
source = args.recording_dir.resolve()
corrections = json.loads(Path(__file__).with_name('narration-corrections.json').read_text())
texts = {clip['id']: clip['text'] for clip in corrections['clips']}

def duration(path):
    return float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', str(path)]))

manifest = [{key: value for key, value in item.items() if key in ('scene', 'text', 'file', 'duration')}
            for item in json.loads((source / 'premium/manifest.json').read_text())]
for index, name in [(1, 'login'), (3, 'upload')]:
    file = work / (name + '.mp3')
    manifest[index].update(text=texts[name], file=str(file), duration=duration(file))

# Render the product label in English rather than asking a Dutch voice segment
# to guess its pronunciation. Keep the same narrator and level across the join.
pcm = bytearray()
segments = []
for index, name in enumerate(['stats-prefix', 'webtrees-english', 'stats-rest']):
    audio = subprocess.check_output([
        'ffmpeg', '-v', 'error', '-i', str(work / (name + '.mp3')),
        '-af', 'silenceremove=start_periods=1:start_duration=0.015:start_threshold=-50dB,'
               'areverse,silenceremove=start_periods=1:start_duration=0.015:start_threshold=-50dB,'
               'areverse,loudnorm=I=-18:TP=-2:LRA=7',
        '-f', 's16le', '-ac', '1', '-ar', '48000', '-'])
    if index:
        pcm.extend(bytes(round(48000 * 0.12) * 2))
    segments.append({'name': name, 'start': len(pcm) / 96000, 'duration': len(audio) / 96000})
    pcm.extend(audio)
combined = work / 'stats-combined.wav'
with wave.open(str(combined), 'wb') as out:
    out.setnchannels(1)
    out.setsampwidth(2)
    out.setframerate(48000)
    out.writeframes(pcm)
manifest[6].update(text='Klik op de knop Open webtrees. ' + texts['stats-rest'],
                   file=str(combined), duration=duration(combined))
(work / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
(work / 'speech-segments.json').write_text(json.dumps(segments, indent=2) + '\n')
print('Prepared corrected narration; English label is a separately generated English segment.')

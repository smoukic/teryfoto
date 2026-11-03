"""Apply the photographed rows exported from Adobe's rendered DOM, without reordering by columns."""
import hashlib
import json
from pathlib import Path
import re

root=Path(__file__).resolve().parents[1]
source=json.loads((root/'storage/adobe-source.json').read_text(encoding='utf-8'))
layouts=json.loads((root/'storage/adobe-order-layout.json').read_text(encoding='utf-8'))
path=root/'app/data/portfolio.json'
pages=json.loads(path.read_text(encoding='utf-8'))
identity=lambda url: re.search(r'/([a-f0-9-]{36})_',url).group(1)

def local_src(info):
    choices=[]
    for candidate in (info.get('srcset') or '').split(','):
        parts=candidate.strip().split()
        if len(parts)==2 and parts[1].endswith('w') and int(parts[1][:-1])<=1400:
            choices.append((int(parts[1][:-1]),parts[0]))
    url=max(choices)[1] if choices else info['src']
    return '/assets/portfolio/'+hashlib.sha256(url.encode()).hexdigest()[:24]+'.webp'

for layout in layouts:
    page=next(p for p in pages if p['slug']==layout['slug'])
    exported=next(p for p in source['pages'] if p['slug']==layout['slug'])
    by_path={Path(i['src']).stem:i for i in page['images']}
    by_identity={identity(i['src']):by_path[Path(local_src(i)).stem] for i in exported['images'] if i['src'].startswith('https://cdn.myportfolio.com/')}
    images=[]; rows=[]
    for module in layout['modules']:
        visual=sorted(module,key=lambda i:(round(i['y']/3),i['x']))
        groups=[]
        for photo in visual:
            if not groups or abs(photo['y']-groups[-1][0]['y'])>3: groups.append([])
            groups[-1].append(photo)
        for group in groups:
            group.sort(key=lambda i:i['x'])
            indices=[]; weights=[]; ratios=[]
            total=sum(i['w'] for i in group)
            width=(max(i['x']+i['w'] for i in group)-min(i['x'] for i in group))/layout['width'] if total else .8
            for photo in group:
                mapped=by_identity[identity(photo['src'])]
                indices.append(len(images)); images.append(mapped)
                weights.append(round(photo['w']/total,6) if total else 1/len(group))
                ratios.append(round(photo['w']/photo['h'],6) if photo['w'] and photo['h'] else None)
            rows.append(dict(images=indices,width=round(min(1,max(.1,width)),6),weights=weights,ratios=ratios))
    assert len(images)==len(page['images']),layout['slug']+' image count mismatch'
    assert sorted(i['src'] for i in images)==sorted(i['src'] for i in page['images']),layout['slug']+' lost or duplicated image'
    page['images']=images; page['rows']=rows
    print(layout['slug']+': '+str(len(images))+' photos in '+str(len(rows))+' original rows')
path.write_text(json.dumps(pages,ensure_ascii=False,indent=2),encoding='utf-8')

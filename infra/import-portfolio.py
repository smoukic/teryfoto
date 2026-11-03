"""Import the owner-authorized rendered Adobe Portfolio export (storage/adobe-source.json)."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import html
import json
from pathlib import Path
import re
import urllib.request

root = Path(__file__).resolve().parents[1]
source = json.loads((root/'storage/adobe-source.json').read_text(encoding='utf-8'))
target = root/'public/assets/portfolio'
target.mkdir(parents=True,exist_ok=True)

def image(info):
    candidates=[]
    for part in (info.get('srcset') or '').split(','):
        pair=part.strip().split()
        if len(pair)==2 and pair[1].endswith('w'):
            candidates.append((int(pair[1][:-1]),pair[0]))
    fitting=[p for p in candidates if p[0]<=1400]
    url=max(fitting)[1] if fitting else info['src']
    if not url.startswith('https://cdn.myportfolio.com/064c12bc-51b9-4074-b1b9-2581faceb557/'):
        raise ValueError('Unexpected asset source')
    suffix=Path(url.split('?')[0]).suffix.lower()
    name=hashlib.sha256(url.encode()).hexdigest()[:24]+suffix
    path=target/name
    if not path.exists():
        with urllib.request.urlopen(url,timeout=35) as response:
            content=response.read()
            if not response.headers.get('Content-Type','').startswith('image/'):
                raise ValueError('Not an image')
        path.write_bytes(content)
    return dict(src='/assets/portfolio/'+name,alt=html.unescape(html.unescape(info.get('alt',''))))

tasks={}
for page in source['pages']:
    for info in page['images']:
        if info['src'].startswith('https://cdn.myportfolio.com/'):
            tasks[info['src']]=info
for cover in source['covers']:
    for info in cover['images']:
        tasks[info['src']]=info
results={}
def fetch(item):
    url,info=item
    try: return url,image(info),None
    except Exception as error: return url,None,str(error)
with ThreadPoolExecutor(max_workers=8) as pool:
    for url,result,error in pool.map(fetch,tasks.items()):
        if error: print('FAILED '+url.split('/')[-1].split('?')[0]+': '+error)
        else: results[url]=result

pages=[]
for page in source['pages']:
    seen=set(); images=[]
    for info in page['images']:
        result=results.get(info['src'])
        if result and result['src'] not in seen:
            seen.add(result['src']); images.append(result)
    cover=next((c for c in source['covers'] if c['slug']==page['slug']),None)
    cover_image=next((results[i['src']] for i in cover['images'] if i['src'] in results),None) if cover else None
    pages.append(dict(slug=page['slug'],title=page['title'],text=page.get('text',''),images=images,cover=cover_image or (images[0] if images else None),embeds=[u for u in page.get('embeds',[]) if u.startswith('https://www-ccv.adobe.io/')]))
(root/'app/data').mkdir(exist_ok=True)
(root/'app/data/portfolio.json').write_text(json.dumps(pages,ensure_ascii=False,indent=2),encoding='utf-8')
print('Imported '+str(len(results))+'/'+str(len(tasks))+' assets; '+str(sum(len(p['images']) for p in pages))+' gallery images.')
if len(results)!=len(tasks): raise SystemExit(1)

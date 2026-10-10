"""Legge i dump di sola struttura (unicoagent.sql, unicobpm.sql, ...) e produce schema2.json.

Uso: python3 parse2.py <cartella-dump>
"""
import re, json, sys, os
D=sys.argv[1].rstrip("/")+"/"
files={"agent":"unicoagent.sql","bpm":"unicobpm.sql","loan":"unicoloan.sql","oam":"unicooam.sql","proforma":"proforma.sql"}
colre=re.compile(r"^`(\w+)` (\w+)(\([^)]*\))?( unsigned)?(.*)$")
out={}
for app,f in files.items():
    s=open(D+f,encoding="utf8").read()
    T={}
    for m in re.finditer(r"CREATE TABLE `(\w+)` \((.*?)\n\)(.*?);",s,re.S):
        name,body,tail=m.groups()
        cols=[];keys=[]
        for line in body.split("\n"):
            line=line.strip().rstrip(",")
            cm=colre.match(line)
            if cm:
                n,base,args,uns,rest=cm.groups()
                d=re.search(r"DEFAULT ('(?:[^']|'')*'|[\w.()]+)",rest)
                c=re.search(r"COMMENT '((?:[^']|'')*)'",rest)
                cols.append(dict(name=n,base=base.lower(),args=(args or "")[1:-1] if args else "",unsigned=bool(uns),
                  nullable="NOT NULL" not in rest,default=d.group(1) if d else None,comment=c.group(1).replace("''","'") if c else "",
                  auto="AUTO_INCREMENT" in rest))
                continue
            km=re.match(r"(PRIMARY|UNIQUE)? ?KEY (?:`(\w+)` )?\((.*)\)",line)
            if km:
                keys.append(dict(kind=(km.group(1) or "INDEX"),name=km.group(2),cols=re.findall(r"`(\w+)`",km.group(3))))
            fm=re.search(r"FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`(\w+)`\)(.*)",line)
            if fm: keys.append(dict(kind="FK",cols=[fm.group(1)],ref=fm.group(2),refcol=fm.group(3),ondelete=(re.search(r"ON DELETE (CASCADE|SET NULL|RESTRICT|NO ACTION)",fm.group(4)) or [None,None])[1]))
        cmt=re.search(r"COMMENT='((?:[^']|'')*)'",tail)
        T[name]=dict(cols=cols,keys=keys,comment=cmt.group(1).replace("''","'") if cmt else "")
    views=set(re.findall(r"VIEW `(\w+)`",s))
    for v in views: T.pop(v,None)
    out[app]=T
json.dump(out,open(os.path.join(os.path.dirname(os.path.abspath(__file__)),"schema2.json"),"w"),indent=1,ensure_ascii=False)
print({a:len(t) for a,t in out.items()})

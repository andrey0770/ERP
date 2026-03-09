import json, sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

with open('catalogue/catalog_new.trln', encoding='utf-8') as f:
    data = json.load(f)

nodes = {n['uid']: n for n in data['nodes']}

def count_leaves(uid):
    node = nodes[uid]
    ch = node.get('children', [])
    if not ch:
        return 1
    return sum(count_leaves(c) for c in ch)

def print_tree(uid, indent=0):
    node = nodes[uid]
    name = node['data'].get('\u0418\u043c\u044f', '???')
    kids = node.get('children', [])
    leaves = count_leaves(uid) if kids else 0
    if kids:
        print('  ' * indent + f'{name}  [{leaves}]')
        for c in kids:
            print_tree(c, indent + 1)
    else:
        if indent <= 3:
            print('  ' * indent + name)

root_uid = data.get('properties', {}).get('topnodes', [None])[0]
if not root_uid:
    for n in data['nodes']:
        if '\u0411\u0438\u043b\u044c\u044f\u0440\u0434' in n['data'].get('\u0418\u043c\u044f', ''):
            root_uid = n['uid']
            break

print_tree(root_uid)

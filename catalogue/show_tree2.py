import json

with open('catalogue/catalog_new.trln', encoding='utf-8') as f:
    data = json.load(f)

nodes = {n['uid']: n for n in data['nodes']}

def count_leaves(uid):
    node = nodes[uid]
    ch = node.get('children', [])
    if not ch:
        return 1
    return sum(count_leaves(c) for c in ch)

def tree(uid, indent=0, max_depth=3):
    node = nodes[uid]
    name = node['data'].get('\u0418\u043c\u044f', '???')
    kids = node.get('children', [])
    leaves = count_leaves(uid) if kids else 0
    if kids:
        lines.append('  ' * indent + name + f'  [{leaves}]')
        if indent < max_depth:
            for c in kids:
                tree(c, indent + 1, max_depth)
    # skip leaf-level products for brevity

root_uid = data.get('properties', {}).get('topnodes', [None])[0]
if not root_uid:
    for n in data['nodes']:
        if '\u0411\u0438\u043b\u044c\u044f\u0440\u0434' in n['data'].get('\u0418\u043c\u044f', ''):
            root_uid = n['uid']
            break

lines = []
tree(root_uid, max_depth=4)

with open('catalogue/tree_out.txt', 'w', encoding='utf-8-sig') as f:
    f.write('\n'.join(lines) + '\n')

print(f'Done: {len(lines)} lines')

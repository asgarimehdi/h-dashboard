"""Add help button + modal to all Livewire pages that don't have them."""
import re
import os

BASE = '/home/boxd/h-dashboard/resources/views/livewire'

# Map section names to pages
page_sections = {
    'kargozini/person.blade.php': 'persons',
    'kargozini/semat.blade.php': 'persons',       # part of kargozini
    'kargozini/tahsil.blade.php': 'persons',
    'kargozini/estekhdam.blade.php': 'persons',
    'kargozini/radif.blade.php': 'persons',
    'tickets/⚡create.blade.php': 'tickets',
    'tickets/⚡inbox.blade.php': 'tickets',
    'tickets/⚡monitoring.blade.php': 'tickets',
    'units/index.blade.php': 'units',
    'units/chart.blade.php': 'units',
    'maps/unit.blade.php': 'maps',
    'maps/route.blade.php': 'maps',
    'maps/route2.blade.php': 'maps',
    'maps/county.blade.php': 'maps',
    'maps/point.blade.php': 'maps',
    'reports/index.blade.php': 'reports',
    'reports/persons.blade.php': 'reports',
    'reports/todos.blade.php': 'reports',
    'reports/units.blade.php': 'reports',
    'reports/advanced.blade.php': 'reports',
    'reports/map-no-boundary.blade.php': 'reports',
    'it/networks.blade.php': 'maps',           # IT monitoring - relevant to maps/networks
    'it/wireless.blade.php': 'maps',
    'settings/index.blade.php': 'settings',
    'users/index.blade.php': 'users',
    'roles/index.blade.php': 'roles',
    'permissions/index.blade.php': 'permissions',
    'todo/todo.blade.php': 'todos',
    'activity-log/index.blade.php': 'activity-log',
    'chat/index.blade.php': 'chat',           # general AI chat
}

# Files that already have help (skip them)
have_help = {'dashboard.blade.php', 'hardware/index.blade.php', 'hardware/ai-chat.blade.php', 'hardware/import-hardware/import-hardware.blade.php'}

# Files where showHelpModal already exists
existing_help_prop = {'dashboard.blade.php', 'hardware/index.blade.php', 'hardware/ai-chat.blade.php', 'hardware/import-hardware/import-hardware.blade.php'}

def add_help_to_page(filepath, section):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    changes = []
    
    # Check if already has help
    if 'x-help:button' in content or 'x-help:modal' in content:
        print(f"  SKIP (already has help): {filepath}")
        return
    
    # 1. Add showHelpModal property to the PHP class
    # Look for the return new class block
    # Add after an existing property declaration
    prop_pattern = r'(public \w+\s+\$\w+\s*=.*?;\n)'
    
    # Find first property after class definition
    match = re.search(r'return new class extends Component', content)
    if match:
        after_class = content[match.end():]
        # Find first public property
        prop_match = re.search(prop_pattern, after_class)
        if prop_match:
            insert_pos = match.end() + prop_match.end()
            before = content[:insert_pos]
            after = content[insert_pos:]
            
            if 'bool $showHelpModal' not in content:
                new_content = before + '\n    public bool $showHelpModal = false;\n' + after
                content = new_content
                changes.append("added showHelpModal")
    
    # 2. Add help button inside <x-slot:actions> of the header
    # Pattern: </x-slot:actions> -> add before existing theme selector or before closing
    header_pattern = r'(<x-header title="[^"]*" separator[^>]*>\s*<x-slot:actions>\s*)(.*?)(\s*</x-slot:actions>)'
    
    def add_button(m):
        opening = m.group(1)
        existing = m.group(2)
        closing = m.group(3)
        help_btn = '            <x-help:button section="' + section + '" wireModel="showHelpModal" />'
        # Add before theme selector if it exists, else at beginning
        if '<x-theme-selector' in existing:
            existing = existing.replace('<x-theme-selector', help_btn + '\n            <x-theme-selector')
        else:
            existing = help_btn + '\n' + existing
        return opening + existing + closing
    
    content = re.sub(header_pattern, add_button, content)
    changes.append("added help button to header")
    
    # 3. Add help modal after header
    # Look for </x-header> and add after it
    xheader_close = '</x-header>\n'
    if xheader_close in content:
        modal_html = f'    <x-help:modal wireModel="showHelpModal" section="{section}" />\n\n'
        content = content.replace(xheader_close, xheader_close + modal_html, 1)
        changes.append("added help modal")
    
    # Write back
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)
    
    if changes:
        print(f"  OK ({', '.join(changes)}): {filepath}")
    else:
        print(f"  NO-CHANGE: {filepath}")

# Process all pages
for relpath, section in page_sections.items():
    filepath = os.path.join(BASE, relpath)
    if not os.path.exists(filepath):
        print(f"  NOT-FOUND: {filepath}")
        continue
    filename = os.path.basename(relpath)
    if filename in have_help or relpath in have_help:
        print(f"  SKIP (already wired): {filepath}")
        continue
    add_help_to_page(filepath, section)

print("\nDone!")
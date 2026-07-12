# Plan 001: Add factories for domain models
Drift: git diff --stat 25c206a4..HEAD -- database/ app/
Commit: 25c206a4 | Priority: P1 | Effort: M | Risk: LOW | Category: tests

## Why
Only UserFactory exists. 19 models lack factories, making tests tedious.

## Commands
php artisan make:factory XxxFactory --model=Xxx
php artisan tinker --execute="factory(App\Models\\Xxx::class)->make();"
vendor/bin/pint --dirty

## Steps
### 1. PersonFactory
php artisan make:factory PersonFactory --model=Person
Fields: n_code, f_name, l_name, e_id, t_id, s_id, r_id, u_id
Verify: php artisan tinker --execute='echo factory(App\Models\Person::class)->make()->n_code;'

### 2. TicketFactory
php artisan make:factory TicketFactory --model=Ticket
Fields: ticket_code, user_id, unit_id, subject, content, priority, status, task_id, current_assignee_id, accepted_at, completed_at
Verify: php artisan tinker --execute='echo factory(App\Models\Ticket::class)->make()->subject;'

### 3. UnitFactory
php artisan make:factory UnitFactory --model=Unit
Fields: name, description, region_id, parent_id, unit_type_id, boundary_id, lat, lng, is_active, can_receive_tickets
Verify: php artisan tinker --execute='echo factory(App\Models\Unit::class)->make()->name;'

### 4. TodoFactory
php artisan make:factory TodoFactory --model=Todo
Fields: title, start_at, end_at, is_completed, unit_id
Verify: php artisan tinker --execute='echo factory(App\Models\Todo::class)->make()->title;'

### 5. Lookup factories (one command each)
php artisan make:factory EstekhdamFactory --model=Estekhdam
php artisan make:factory TahsilFactory --model=Tahsil
php artisan make:factory SematFactory --model=Semat
php artisan make:factory RadifFactory --model=Radif
php artisan make:factory UnitTypeFactory --model=UnitType
php artisan make:factory TaskActivityFactory --model=TaskActivity
php artisan make:factory AttachmentFactory --model=Attachment
Verify each: php artisan tinker --execute='echo factory(App\Models\Xxx::class)->make()->id;'

## Done
- [ ] 11 new factory files in database/factories/
- [ ] Each ->make() succeeds without error
- [ ] vendor/bin/pint --dirty exits 0
- [ ] No files outside database/factories/ modified

## STOP
Stop if a model has a fillable field not listed here. Stop if ->make() produces a type error.
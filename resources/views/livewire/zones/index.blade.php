<div class="p-6 rtl">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">مدیریت زون‌ها</h1>
        <x-button wire:click="createZone" class="btn-primary">
            <x-heroicon-o-plus class="h-5 w-5 ml-2" />
            افزودن زون جدید
        </x-button>
    </div>

    <!-- Search and Filters -->
    <div class="card bg-base-100 shadow-xl mb-6">
        <div class="card-body p-4">
            <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                <div class="flex-1 max-w-md">
                    <label class="input input-bordered w-full">
                        <input type="text"
                               wire:model.live.debounce.300ms="search"
                               placeholder="جستجو در نام یا توضیحات..."
                               class="bg-transparent border-none focus:ring-0 focus:border-none"
                               autocomplete="off" />
                        <x-heroicon-o-magnifying-glass class="h-5 w-5 text-base-content/50" slot="start" />
                    </label>
                </div>
                <div class="flex gap-2">
                    <x-select wire:model="perPage" class="w-32">
                        <option value="10">10 در صفحه</option>
                        <option value="15">15 در صفحه</option>
                        <option value="25">25 در صفحه</option>
                        <option value="50">50 در صفحه</option>
                    </x-select>
                </div>
            </div>
        </div>
    </div>

    <!-- Zones Table -->
    <div class="card bg-base-100 shadow-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table w-full">
                <thead class="bg-base-200">
                    <tr>
                        <th wire:click="sortBy('name')" class="cursor-pointer">
                            نام <x-heroicon-o-arrows-up-down class="h-4 w-4 inline ml-1" />
                        </th>
                        <th wire:click="sortBy('color')" class="cursor-pointer">
                            رنگ <x-heroicon-o-arrows-up-down class="h-4 w-4 inline ml-1" />
                        </th>
                        <th wire:click="sortBy('is_active')" class="cursor-pointer">
                            وضعیت <x-heroicon-o-arrows-up-down class="h-4 w-4 inline ml-1" />
                        </th>
                        <th wire:click="sortBy('created_at')" class="cursor-pointer">
                            تاریخ ایجاد <x-heroicon-o-arrows-up-down class="h-4 w-4 inline ml-1" />
                        </th>
                        <th>تعداد واحدها</th>
                        <th class="text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($zones as $zone)
                        <tr class="hover:bg-base-50 transition-colors">
                            <td>
                                <div class="font-medium text-gray-900">{{ $zone->name }}</div>
                                @if ($zone->description)
                                    <div class="text-sm text-gray-500 line-clamp-1">{{ $zone->description }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded border" style="background-color: {{$zone->color}}"></div>
                                    <span class="text-sm font-mono">{{ $zone->color }}</span>
                                </div>
                            </td>
                            <td>
                                <x-badge :class="$zone->is_active ? 'badge-success' : 'badge-ghost'">
                                    {{ $zone->is_active ? 'فعال' : 'غیرفعال' }}
                                </x-badge>
                            </td>
                            <td class="text-sm text-gray-500">{{ $zone->created_at->format('Y/m/d') }}</td>
                            <td>
                                <span class="badge badge-outline badge-info">{{ $zone->units_count ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                <div class="dropdown dropdown-end">
                                    <label tabindex="0" class="btn btn-ghost btn-sm btn-circle">
                                        <x-heroicon-o-ellipsis-vertical class="h-5 w-5" />
                                    </label>
                                    <ul tabindex="0" class="dropdown-content menu menu-compact dropdown-right w-48 bg-base-100 rounded-box shadow-lg">
                                        <li>
                                            <button wire:click="editZone({{ $zone->id }})"
                                                    class="flex items-center gap-2 w-full px-3 py-2 text-gray-700 hover:bg-base-200">
                                                <x-heroicon-o-pencil class="h-4 w-4" />
                                                ویرایش
                                            </button>
                                        </li>
                                        <li>
                                            <button wire:click="manageUnits({{ $zone->id }})"
                                                    class="flex items-center gap-2 w-full px-3 py-2 text-gray-700 hover:bg-base-200">
                                                <x-heroicon-o-users class="h-4 w-4" />
                                                مدیریت واحدها
                                            </button>
                                        </li>
                                        <li>
                                            <button wire:click="deleteZone({{ $zone->id }})"
                                                    class="flex items-center gap-2 w-full px-3 py-2 text-red-600 hover:bg-red-50">
                                                <x-heroicon-o-trash class="h-4 w-4" />
                                                حذف
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-12 text-gray-500">
                                <x-heroicon-o-inbox class="h-12 w-12 mx-auto mb-4 text-gray-300" />
                                هیچ زونی یافت نشد
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($zones->hasPages())
            <div class="card-body border-t border-base-200">
                {{ $zones->links('vendor.pagination.tailwind') }}
            </div>
        @endif
    </div>

    <!-- Create Modal -->
    @if ($showCreateModal)
        <x-modal wire:model="showCreateModal" class="max-w-md">
            <x-slot name="title">ایجاد زون جدید</x-slot>

            <!-- Name -->
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">نام زون *</span>
                                </label>
                                <input type="text" wire:model="name" class="input input-bordered w-full" placeholder="نام زون را وارد کنید" />
                                @error('name') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                            </div>

                            <!-- Slug -->
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">شناسه یکتا (Slug) *</span>
                                </label>
                                <input type="text" wire:model="slug" class="input input-bordered w-full" placeholder="شناسه یکتای انگلیسی (مثل: north-zone)" />
                                @error('slug') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                                <p class="text-xs text-base-content/50 mt-1">برای آدرس‌دهی و استفاده در API. اگر خالی بگذارید از نام زون تولید می‌شود.</p>
                            </div>

                            <!-- Description -->
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">توضیحات</span>
                                </label>
                                <textarea wire:model="description" class="textarea textarea-bordered w-full" rows="3" placeholder="توضیحات اختیاری"></textarea>
                            </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">رنگ *</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model="color" class="w-10 h-10 rounded border cursor-pointer" />
                        <input type="text" wire:model="color" class="input input-bordered flex-1 font-mono" placeholder="#3B82F6" />
                    </div>
                    @error('color') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input type="checkbox" wire:model="is_active" class="checkbox checkbox-primary" />
                        <span class="label-text">فعال</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <x-button type="button" wire:click="closeModals" variant="ghost">انصراف</x-button>
                    <x-button type="submit" variant="primary">ذخیره</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    <!-- Edit Modal -->
    @if ($showEditModal)
        <x-modal wire:model="showEditModal" class="max-w-md">
            <x-slot name="title">ویرایش زون</x-slot>

            <!-- Name -->
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">نام زون *</span>
                                </label>
                                <input type="text" wire:model="name" class="input input-bordered w-full" placeholder="نام زون را وارد کنید" />
                                @error('name') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                            </div>

                            <!-- Slug -->
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">شناسه یکتا (Slug) *</span>
                                </label>
                                <input type="text" wire:model="slug" class="input input-bordered w-full" placeholder="شناسه یکتای انگلیسی (مثل: north-zone)" />
                                @error('slug') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                                <p class="text-xs text-base-content/50 mt-1">برای آدرس‌دهی و استفاده در API. اگر خالی بگذارید از نام زون تولید می‌شود.</p>
                            </div>

                            <!-- Description -->
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">توضیحات</span>
                                </label>
                                <textarea wire:model="description" class="textarea textarea-bordered w-full" rows="3" placeholder="توضیحات اختیاری"></textarea>
                            </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">رنگ *</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model="color" class="w-10 h-10 rounded border cursor-pointer" />
                        <input type="text" wire:model="color" class="input input-bordered flex-1 font-mono" placeholder="#3B82F6" />
                    </div>
                    @error('color') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input type="checkbox" wire:model="is_active" class="checkbox checkbox-primary" />
                        <span class="label-text">فعال</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <x-button type="button" wire:click="closeModals" variant="ghost">انصراف</x-button>
                    <x-button type="submit" variant="primary">ذخیره تغییرات</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    <!-- Manage Units Modal -->
    @if ($showUnitsModal)
        <x-modal wire:model="showUnitsModal" class="max-w-2xl">
            <x-slot name="title">مدیریت واحدهای زون: {{ $unitsZone->name }}</x-slot>

            <div class="space-y-4">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">انتخاب واحدها</span>
                    </label>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 max-h-96 overflow-y-auto p-2 border border-base-300 rounded">
                        @foreach ($availableUnits as $id => $name)
                            <label class="label cursor-pointer justify-start gap-2 p-2 hover:bg-base-100 rounded">
                                <input type="checkbox"
                                       wire:model="selectedUnits"
                                       value="{{ $id }}"
                                       class="checkbox checkbox-primary" />
                                <span class="label-text text-sm">{{ $name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-button type="button" wire:click="closeModals" variant="ghost">انصراف</x-button>
                    <x-button wire:click="saveUnits" variant="primary">ذخیره واحدها</x-button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
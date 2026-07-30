<div class="p-6 rtl">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">ایجاد زون جدید</h1>
            <p class="text-gray-500 mt-1">اطلاعات زون جدید را وارد کنید</p>
        </div>
        <x-button wire:click="cancel" variant="ghost">
            <x-heroicon-o-arrow-left class="h-5 w-5 ml-2" />
            بازگشت
        </x-button>
    </div>

    <!-- Form -->
    <div class="card bg-base-100 shadow-xl max-w-2xl">
        <div class="card-body p-6 space-y-6">
            <form wire:submit="save" class="space-y-6">
                <!-- Name -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">نام زون <span class="text-error">*</span></span>
                    </label>
                    <input type="text"
                           wire:model="name"
                           class="input input-bordered w-full"
                           placeholder="نام زون را وارد کنید" />
                    @error('name')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Description -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">توضیحات</span>
                    </label>
                    <textarea wire:model="description"
                              class="textarea textarea-bordered w-full"
                              rows="4"
                              placeholder="توضیحات اختیاری"></textarea>
                </div>

                <!-- Color -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">رنگ <span class="text-error">*</span></span>
                    </label>
                    <div class="flex items-center gap-3">
                        <input type="color"
                               wire:model="color"
                               class="w-12 h-12 rounded border cursor-pointer" />
                        <input type="text"
                               wire:model="color"
                               class="input input-bordered flex-1 font-mono"
                               placeholder="#3B82F6" />
                    </div>
                    @error('color')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-base-content/50 mt-1">این رنگ برای نمایش زون روی نقشه استفاده می‌شود.</p>
                </div>

                <!-- Status -->
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox"
                               wire:model="is_active"
                               class="checkbox checkbox-primary" />
                        <span class="label-text">فعال</span>
                    </label>
                </div>

                <!-- Units Assignment -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">واحدهای وابسته</span>
                    </label>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 max-h-96 overflow-y-auto p-3 border border-base-300 rounded">
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
                    <p class="text-xs text-base-content/50 mt-1">واحدهای مربوط به این زون را انتخاب کنید (اختیاری).</p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4 border-t border-base-200">
                    <x-button type="button" wire:click="cancel" variant="ghost">انصراف</x-button>
                    <x-button type="submit" variant="primary">
                        <span wire:loading.remove>ایجاد زون</span>
                        <span wire:loading>
                            <span class="loading loading-spinner loading-sm"></span>
                            در حال ایجاد...
                        </span>
                    </x-button>
                </div>
            </form>
        </div>
    </div>
</div>
{{-- Pairs list --}}
<x-app-layout>
    <div class="container mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">جفتر‌های ترافیک</h1>
                <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">مقایسه دو آیتم برای نمایش گراف‌های ترکیبی</p>
            </div>
            <a href="{{ route('zabbix.index') }}" class="btn btn-ghost btn-sm">
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                بازگشت
            </a>
        </div>

        {{-- Filters --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
            <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center">
                <div class="flex-1 sm:w-64">
                    <input type="text" wire:model.live="search" placeholder="جستجوی جفتر..." class="input input-bordered w-full" />
                </div>
                <div class="sm:w-48">
                    <select wire:model.live="hostFilter" class="select select-bordered w-full">
                        <option value="">همه هاست‌ها</option>
                        @foreach ($hosts as $host)
                            <option value="{{ $host->id }}">{{ $host->visible_name }}</option>
                        @endforeach
                    </select>
                </div>
                <select wire:model.live="perPage" class="select select-bordered w-32">
                    <option value="10">۱۰</option>
                    <option value="15">۱۵</option>
                    <option value="25">۲۵</option>
                    <option value="50">۵۰</option>
                </select>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">نام</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">هاست</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">آیتم ورودی (In)</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">آیتم خروجی (Out)</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">واحد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">فعال</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($pairs as $pair)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">{{ $pair->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ $pair->host->visible_name ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    <span class="inline-block bg-gray-100 dark:bg-gray-700 rounded px-2 py-0.5 text-xs font-mono">{{ $pair->inItem?->name ?? '—' }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    <span class="inline-block bg-gray-100 dark:bg-gray-700 rounded px-2 py-0.5 text-xs font-mono">{{ $pair->outItem?->name ?? '—' }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ $pair->unit?->name ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $pair->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' }}">
                                        {{ $pair->is_active ? 'فعال' : 'غیرفعال' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">هیچ جفتری یافت نشد</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                {{ $pairs->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
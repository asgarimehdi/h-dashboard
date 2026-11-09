<?php

namespace Database\Seeders;

use App\Models\Disease;
use App\Models\DiseaseFieldDef;
use Illuminate\Database\Seeder;

class DiseaseSeeder extends Seeder
{
    /**
     * Starter disease catalogue (#1240, §3.3).
     *
     * Idempotent on purpose: DatabaseSeeder runs in every environment, so a
     * repeated `migrate --seed` or `db:seed` must converge to the same five
     * diseases and seven field definitions instead of hitting the unique
     * indexes or duplicating rows.
     */
    public function run(): void
    {
        /** @var array<int, array{slug: string, name_fa: string, sort: int, fields: array<int, array{field_key: string, label_fa: string, type: string, unit: string|null, options: array<int, string>|null, required: bool}>}> $catalogue */
        $catalogue = [
            [
                'slug' => 'htn',
                'name_fa' => 'فشار خون',
                'sort' => 1,
                'fields' => [
                    ['field_key' => 'sys', 'label_fa' => 'فشار سیستولیک', 'type' => 'number', 'unit' => 'mmHg', 'options' => null, 'required' => true],
                    ['field_key' => 'dia', 'label_fa' => 'فشار دیاستولیک', 'type' => 'number', 'unit' => 'mmHg', 'options' => null, 'required' => true],
                ],
            ],
            [
                'slug' => 'thalassemia',
                'name_fa' => 'تالاسمی',
                'sort' => 2,
                'fields' => [
                    ['field_key' => 'hb', 'label_fa' => 'هموگلوبین', 'type' => 'number', 'unit' => 'g/dL', 'options' => null, 'required' => true],
                    ['field_key' => 'type', 'label_fa' => 'نوع تالاسمی', 'type' => 'select', 'unit' => null, 'options' => ['β/thal', 'δβ', 'α/thal'], 'required' => true],
                ],
            ],
            [
                'slug' => 'diabetes',
                'name_fa' => 'دیابت',
                'sort' => 3,
                'fields' => [
                    ['field_key' => 'fbs', 'label_fa' => 'قند ناشتا', 'type' => 'number', 'unit' => 'mg/dL', 'options' => null, 'required' => true],
                ],
            ],
            [
                'slug' => 'hiv',
                'name_fa' => 'اچ‌آی‌وی',
                'sort' => 4,
                'fields' => [
                    ['field_key' => 'cd4', 'label_fa' => 'CD4', 'type' => 'number', 'unit' => 'cell/µL', 'options' => null, 'required' => false],
                ],
            ],
            [
                'slug' => 'cancer',
                'name_fa' => 'سرطان',
                'sort' => 5,
                'fields' => [
                    ['field_key' => 'stage', 'label_fa' => 'مرحله', 'type' => 'select', 'unit' => null, 'options' => ['I', 'II', 'III', 'IV'], 'required' => true],
                ],
            ],
        ];

        foreach ($catalogue as $data) {
            $disease = Disease::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name_fa' => $data['name_fa'],
                    'sort' => $data['sort'],
                    'is_active' => true,
                ]
            );

            foreach ($data['fields'] as $index => $field) {
                DiseaseFieldDef::query()->firstOrCreate(
                    [
                        'disease_id' => $disease->id,
                        'field_key' => $field['field_key'],
                    ],
                    [
                        'label_fa' => $field['label_fa'],
                        'type' => $field['type'],
                        'unit' => $field['unit'],
                        'options' => $field['options'],
                        'required' => $field['required'],
                        'sort' => $index + 1,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}

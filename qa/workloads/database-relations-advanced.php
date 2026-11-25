<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\TableRepository;

final class LabRegionRepository extends TableRepository
{
    protected static string $table = 'lab_regions';

    protected static function relations(): array
    {
        $orders = Relation::hasManyThrough(LabRegionOrderRepository::class, LabRegionMemberRepository::class, 'region_id', 'member_id');

        return [
            'orders' => $orders,
            'latest' => $orders->latestOfMany('sequence_no'),
            'selected' => $orders->ofMany(['sequence_no' => 'max', 'id' => 'min'])->select(['amount']),
            'paid' => $orders->ofMany('sequence_no', 'max', static function (QueryBuilder $query): void {
                $query->where('paid', '=', 1);
            }),
            'profile' => Relation::hasOneThrough(LabRegionProfileRepository::class, LabRegionMemberRepository::class, 'region_id', 'member_id'),
        ];
    }
}

final class LabRegionMemberRepository extends TableRepository
{
    protected static string $table = 'lab_members';

    protected static function relations(): array
    {
        return ['images' => Relation::morphMany(LabRegionImageRepository::class, 'imageable', 'member')];
    }
}

final class LabRegionOrderRepository extends TableRepository
{
    protected static string $table = 'lab_region_orders';

    protected static function relations(): array
    {
        return [
            'member' => Relation::belongsTo(LabRegionMemberRepository::class, 'member_id'),
            'images' => Relation::morphMany(LabRegionImageRepository::class, 'imageable', 'order'),
            'cover' => Relation::morphOne(LabRegionImageRepository::class, 'imageable', 'order'),
            'latest_image' => Relation::morphMany(LabRegionImageRepository::class, 'imageable', 'order')->latestOfMany(),
            'labels' => Relation::morphToMany(LabRegionTagRepository::class, 'lab_tag_links', 'taggable_id', 'tag_id', 'taggable_type', 'order')->withPivot('context')->asPivot('tagging'),
        ];
    }
}

final class LabRegionProfileRepository extends TableRepository
{
    protected static string $table = 'lab_profiles';
}

final class LabRegionImageRepository extends TableRepository
{
    protected static string $table = 'lab_images';

    protected static function relations(): array
    {
        return [
            'subject' => Relation::morphTo('imageable_type', 'imageable_id', [
                'order' => LabRegionOrderRepository::class,
                'member' => LabRegionMemberRepository::class,
            ]),
        ];
    }
}

final class LabRegionTagRepository extends TableRepository
{
    protected static string $table = 'lab_tags';

    protected static function relations(): array
    {
        return [
            'orders' => Relation::morphedByMany(LabRegionOrderRepository::class, 'lab_tag_links', 'tag_id', 'taggable_id', 'taggable_type', 'order')->withPivot('context')->asPivot('tagging'),
        ];
    }
}

function advancedRelationsWorkload(Connection $db): void
{
    foreach ([
        'CREATE TABLE lab_regions (id INTEGER PRIMARY KEY, name VARCHAR(40) NOT NULL)',
        'CREATE TABLE lab_members (id INTEGER PRIMARY KEY, region_id INTEGER NOT NULL)',
        'CREATE TABLE lab_region_orders (id INTEGER PRIMARY KEY, member_id INTEGER NOT NULL, amount INTEGER NOT NULL, sequence_no INTEGER NOT NULL, paid INTEGER NOT NULL)',
        'CREATE TABLE lab_profiles (id INTEGER PRIMARY KEY, member_id INTEGER NOT NULL, label VARCHAR(40) NOT NULL)',
        'CREATE TABLE lab_images (id INTEGER PRIMARY KEY, imageable_type VARCHAR(40) NOT NULL, imageable_id INTEGER NOT NULL, label VARCHAR(40) NOT NULL)',
        'CREATE TABLE lab_tags (id INTEGER PRIMARY KEY, label VARCHAR(40) NOT NULL)',
        'CREATE TABLE lab_tag_links (taggable_type VARCHAR(40) NOT NULL, taggable_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, context VARCHAR(40) NOT NULL, PRIMARY KEY (taggable_type, taggable_id, tag_id))',
    ] as $statement) {
        $db->statement($statement);
    }
    $db->table('lab_regions')->insert([['id' => 1, 'name' => 'active'], ['id' => 2, 'name' => 'empty']]);
    $db->table('lab_members')->insert([['id' => 1, 'region_id' => 1], ['id' => 2, 'region_id' => 1]]);
    $db->table('lab_region_orders')->insert([
        ['id' => 1, 'member_id' => 1, 'amount' => 10, 'sequence_no' => 1, 'paid' => 1],
        ['id' => 2, 'member_id' => 2, 'amount' => 20, 'sequence_no' => 2, 'paid' => 1],
        ['id' => 3, 'member_id' => 1, 'amount' => 30, 'sequence_no' => 2, 'paid' => 0],
    ]);
    $db->table('lab_profiles')->insert(['id' => 1, 'member_id' => 2, 'label' => 'primary']);
    $db->table('lab_images')->insert([
        ['id' => 1, 'imageable_type' => 'order', 'imageable_id' => 1, 'label' => 'order-cover'],
        ['id' => 2, 'imageable_type' => 'order', 'imageable_id' => 1, 'label' => 'order-latest'],
        ['id' => 3, 'imageable_type' => 'member', 'imageable_id' => 1, 'label' => 'member-only'],
    ]);
    $db->table('lab_tags')->insert([['id' => 1, 'label' => 'active'], ['id' => 2, 'label' => 'member-only']]);
    $db->table('lab_tag_links')->insert([
        ['taggable_type' => 'order', 'taggable_id' => 1, 'tag_id' => 1, 'context' => 'invoice'],
        ['taggable_type' => 'member', 'taggable_id' => 1, 'tag_id' => 2, 'context' => 'profile'],
    ]);
    DB::addConnection(config(), 'lab-advanced-relations');
    DB::setDefaultConnection('lab-advanced-relations');
    $region = LabRegionRepository::query()->where('id', '=', 1)
        ->with('orders.member.images')->with('latest', 'selected', 'paid', 'profile')
        ->withCount('orders')->withSum('orders', 'amount')->withAvg('orders', 'amount')
        ->withMin('orders', 'amount')->withMax('orders', 'amount')->first();
    check(is_array($region) && count($region['orders']) === 3, 'Through relation projection failed.');
    check((int) $region['orders_count'] === 3 && (int) $region['orders_sum_amount'] === 60
        && (float) $region['orders_avg_amount'] === 20.0 && (int) $region['orders_min_amount'] === 10
        && (int) $region['orders_max_amount'] === 30, 'Through aggregates diverged.');
    check((int) $region['latest']['id'] === 3 && (int) $region['paid']['id'] === 2
        && array_keys($region['selected']) === ['amount'] && (int) $region['selected']['amount'] === 20
        && $region['profile']['label'] === 'primary', 'Winner, scope, projection or one-through mismatch.');
    $empty = LabRegionRepository::query()->where('id', '=', 2)->with('orders', 'latest', 'profile')->first();
    check($empty['orders'] === [] && $empty['latest'] === null && $empty['profile'] === null, 'Empty relation shape mismatch.');
    $winners = LabRegionRepository::query()->whereRelation('latest', 'paid', '=', 1)->get()->toArray();
    check($winners === [], 'Winner filter incorrectly promoted older paid row.');
    $match = LabRegionRepository::query()->whereRelation('orders', 'amount', '=', 20)->get()->toArray();
    check(count($match) === 1 && (int) $match[0]['id'] === 1, 'Through existence filter failed.');

    $order = LabRegionOrderRepository::query()->where('id', '=', 1)->with('images', 'cover', 'latest_image', 'labels')->first();
    check(count($order['images']) === 2 && $order['cover'] !== null && (int) $order['latest_image']['id'] === 2, 'Polymorphic image selection failed.');
    check(count($order['labels']) === 1 && (int) $order['labels'][0]['id'] === 1
        && $order['labels'][0]['tagging']['context'] === 'invoice', 'Polymorphic pivot isolation failed.');
    $images = LabRegionImageRepository::query()->with('subject')->get()->toArray();
    check(count($images) === 3, 'Morph-to result count mismatch.');
    foreach ($images as $image) {
        check((int) $image['subject']['id'] === 1
            && array_key_exists($image['imageable_type'] === 'order' ? 'amount' : 'region_id', $image['subject']), 'Morph-to owner type escaped.');
    }
    $tag = LabRegionTagRepository::query()->where('id', '=', 1)->with('orders')->first();
    check(count($tag['orders']) === 1 && (int) $tag['orders'][0]['id'] === 1
        && $tag['orders'][0]['tagging']['context'] === 'invoice', 'Inverse polymorphic pivot failed.');
    echo "Through, polymorphic, winner-first, scoped multi-column projection and aggregates passed\n";
}

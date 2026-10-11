<?php

declare(strict_types=1);

use Siberfx\FacebookAutopost\Posts\FacebookPost;

it('builds the default commentary from title and url', function () {
    $post = FacebookPost::make('https://example.com/blog/hello', 'Hello world');

    expect($post->commentaryText())->toBe('Hello world')
        ->and($post->descriptionText())->toBe('');
});

it('fills {url} and {title} in a custom commentary', function () {
    $post = FacebookPost::make('https://example.com/x', 'X')
        ->commentary('New: {title} → {url} #launch');

    expect($post->commentaryText())->toBe('New: X → https://example.com/x #launch');
});

it('is immutable', function () {
    $base = FacebookPost::make('https://example.com/x', 'X');
    $changed = $base->description('changed');

    expect($base->descriptionText())->toBe('')
        ->and($changed->descriptionText())->toBe('changed');
});

it('limits title to 200 and description to 256 characters', function () {
    $post = FacebookPost::make('https://example.com/x', str_repeat('ü', 300))
        ->description(str_repeat('é', 400));

    expect(mb_strlen($post->title()))->toBe(200)
        ->and(mb_strlen($post->descriptionText()))->toBe(256);
});

it('rejects a relative or non-http url', function (string $url) {
    FacebookPost::make($url, 'X');
})->with(['/blog/x', 'ftp://example.com/x', 'javascript:alert(1)', '', 'blog/çalışma', '//example.com/x'])
    ->throws(InvalidArgumentException::class);

it('percent-encodes a non-ASCII path, query and fragment', function () {
    $post = FacebookPost::make('https://example.com/blog/çalışma?q=ünlü #bölüm', 'X');

    expect($post->url())->toBe('https://example.com/blog/%C3%A7al%C4%B1%C5%9Fma?q=%C3%BCnl%C3%BC%20#b%C3%B6l%C3%BCm')
        ->and($post->commentaryText())->toBe('X');
});

it('leaves an already encoded URL alone', function () {
    expect(FacebookPost::make('https://example.com/blog/%C3%A7al?x=1&y=2', 'X')->url())
        ->toBe('https://example.com/blog/%C3%A7al?x=1&y=2');
});

it('converts an internationalised host to punycode', function () {
    expect(FacebookPost::make('https://şirket.com.tr/x', 'X')->url())->toBe('https://xn--irket-idb.com.tr/x')
        ->and(FacebookPost::make('https://user@şirket.com.tr:8443/çay', 'X')->url())->toBe('https://user@xn--irket-idb.com.tr:8443/%C3%A7ay');
})->skip(! function_exists('idn_to_ascii'), 'ext-intl is not installed');

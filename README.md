# smallworldfs/kount

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

For Laravel 4 version look at [Laravelkount][link-laravel4]

## Install

Via Composer

``` bash
$ composer require smallworldfs/kount
```

Add ServiceProvider in your `app.php` config file.

```php
// config/app.php
'providers' => [
    ...
    Smallworldfs\Kount\KountServiceProvider::class,
]
```

## Configuration

Publish config and migration by running:

``` bash
    php artisan vendor:publish --provider=smallworldfs/kount
```
``` bash
    php artisan migrate
```


## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Testing

``` bash
$ composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CONDUCT](CONDUCT.md) for details.

## Security

If you discover any security related issues, please email smallworldfs@gmail.com instead of using the issue tracker.

## Credits

- [Alberto Sanz Redondo][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/smallworldfs/kount.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/smallworldfs/kount.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/smallworldfs/kount
[link-downloads]: https://packagist.org/packages/smallworldfs/kount
[link-author]: https://github.com/smallworldfs
[link-contributors]: ../../contributors
[link-laravel4]: https://github.com/smallworldfs/laravelkount

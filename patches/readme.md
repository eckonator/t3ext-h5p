# Howto patch composer loaded packages

1. install `composer require cweagans/composer-patches:~2.0`
2. move this packages folder to same level as composer.json
3. add this to composer.json
```
"extra": {
    "patches": {
        "h5p/h5p-editor": {
            "Fix dynamic property deprecation": "patches/h5p-editor_fix-dynamic-property.patch"
        },
        "h5p/h5p-core": {
            "Fix content slug": "patches/h5p-core_content-slug.patch"
        `}`
    }
}
```
4. run `composer patches-relock`
5. run `composer patches-repatch`

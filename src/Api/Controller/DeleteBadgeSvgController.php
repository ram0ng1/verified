<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Api\Controller\AbstractDeleteController;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Http\Message\ServerRequestInterface;

class DeleteBadgeSvgController extends AbstractDeleteController
{
    protected Filesystem $uploadDir;

    protected string $filePathSettingKey = 'ramon-verified.badge_svg_path';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        Factory $filesystemFactory
    ) {
        $this->uploadDir = $filesystemFactory->disk('flarum-assets');
    }

    #[\Override]
    protected function delete(ServerRequestInterface $request): void
    {
        RequestUtil::getActor($request)->assertAdmin();

        $path = $this->settings->get($this->filePathSettingKey);
        $this->settings->delete($this->filePathSettingKey);
        $this->settings->delete('ramon-verified.badge_svg_content');

        if ($path && $this->uploadDir->exists($path)) {
            $this->uploadDir->delete($path);
        }
    }
}

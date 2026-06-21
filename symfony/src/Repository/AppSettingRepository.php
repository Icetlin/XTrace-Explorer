<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->find($key);
        return $row?->getValue() ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $row = $this->find($key);
        if ($row === null) {
            $row = new AppSetting($key, $value);
            $this->getEntityManager()->persist($row);
        } else {
            $row->setValue($value);
        }
        $this->getEntityManager()->flush();
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null) return $default;
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }
}

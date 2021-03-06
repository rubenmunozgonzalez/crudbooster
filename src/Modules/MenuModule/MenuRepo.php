<?php

namespace crocodicstudio\crudbooster\Modules\MenuModule;

use CRUDBooster;
use Illuminate\Support\Facades\DB;

class MenuRepo
{
    public static function sidebarMenu()
    {
        $menuActive = self::table()
            ->where('cms_privileges', CRUDBooster::myPrivilegeId())
            ->where('parent_id', 0)->where('is_active', 1)
            ->where('is_dashboard', 0)
            ->orderby('sorting', 'asc')
            ->select('cms_menus.*')
            ->get();

        foreach ($menuActive as &$menu) {

            $url = self::menuUrl($menu);

            $menu->url = $url;
            $menu->url_path = trim(str_replace(url('/'), '', $url), "/");

            $child = self::table()
                ->where('is_dashboard', 0)
                ->where('is_active', 1)
                ->where('cms_privileges', 'like', '%"'.CRUDBooster::myPrivilegeName().'"%')
                ->where('parent_id', $menu->id)
                ->select('cms_menus.*')
                ->orderby('sorting', 'asc')
                ->get();

            if (count($child)) {
                foreach ($child as &$c) {
                    $url = self::menuUrl($c);
                    $c->url = $url;
                    $c->url_path = trim(str_replace(url('/'), '', $url), "/");
                }
            }
            $menu->children = $child;
        }

        return $menuActive;
    }

    private static function menuUrl($menu)
    {
        $menu->is_broken = false;
        $menuType = $menu->type;
        if ($menuType == MenuTypes::route) {
            return route($menu->path);
        }

        if ($menuType == MenuTypes::url) {
            return $menu->path;
        }

        if ($menuType == MenuTypes::ControllerMethod) {
            return action($menu->path);
        }

        if (in_array($menuType, [MenuTypes::Module, MenuTypes::Statistic])) {
            return CRUDBooster::adminPath($menu->path);
        }

        $menu->is_broken = true;

        return '#';
    }

    public static function sidebarDashboard()
    {
        $menu = self::table()
            ->where('cms_privileges', CRUDBooster::myPrivilegeId())
            ->where('is_dashboard', 1)
            ->where('is_active', 1)->first() ?: new \stdClass();

        $menu->url = self::menuUrl($menu);

        return $menu;
    }

    private static function table()
    {
        return DB::table('cms_menus');
    }

    public static function fetchMenuWithChilds($status = 1)
    {
        $menus = self::fetchMenu(0, $status);

        foreach ($menus as $menu) {
            $child = self::fetchMenu($menu->id, $status);
            if (count($child)) {
                $menu->children = $child;
            }
        }

        return $menus;
    }
    public static function fetchMenu($parent, $status = 1)
    {
        return self::table()->where('parent_id', $parent)->where('is_active', $status)->orderby('sorting', 'asc')->get();
    }
}
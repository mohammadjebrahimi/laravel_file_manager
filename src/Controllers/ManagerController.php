<?php

namespace ArtinCMS\LFM\Controllers;

use ArtinCMS\LFM\Helpers\Classes\Media;
use ArtinCMS\LFM\Models\Category;
use Carbon\Carbon;
use Validator;
use ArtinCMS\LFM\Models\File;
use ArtinCMS\LFM\Models\FileMimeType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use DB;
use Illuminate\Support\Facades\Storage;


class ManagerController extends Controller
{
    //categories
    public function showCategories($insert = false, $callback = false, $section = false)
    {
        if ($section)
        {
            $LFM = session()->get('LFM');
            $trueMimeType = $LFM[$section]['options']['true_mime_type'];
        }
        else
        {
            $trueMimeType = false;
        }
        $files = File::get_uncategory_files($trueMimeType);
        $categories = Category::get_root_categories();
        $category = false;
        $breadcrumbs[] = ['id' => 0, 'title' => 'media', 'type' => 'Enable'];
        $result['parent_category_name'] = 'media';
        return view('laravel_file_manager::index', compact('categories', 'files', 'category', 'breadcrumbs', 'insert', 'section', 'callback'));
    }

    public function createCategory($category_id = 0, $callback = false, $section = false)
    {
        $messages = [];
        $categories = Category::where('user_id', '=', (auth()->check()) ? auth()->id() : 0)->get();
        return view('laravel_file_manager::category', compact('categories', 'category_id', 'messages', 'callback', 'section'));
    }

    public function searchMedia(Request $request)
    {
        $file = [];
        $cat = [];
        $categories = Category::with('user')->select('id', 'title as name', 'user_id', 'parent_category_id', 'description', 'created_at', 'updated_at')
            ->where([
                ['user_id', '=', $this->getUserId()],
                ['title', 'like', '%' . $request->search . '%']
            ])->get()->toArray();
        $files = File::with('user', 'FileMimeType')->select('id', 'originalName as name', 'user_id', 'file_mime_type_id', 'category_id', 'extension', 'mimeType', 'path', 'created_at', 'updated_at')
            ->where([
                ['user_id', '=', $this->getUserId()],
                ['originalName', 'like', '%' . $request->search . '%']
            ])->get()->toArray();

        foreach ($files as $f)
        {
            if ($f['file_mime_type']['icon_class'])
            {
                $f['icon'] = $f['file_mime_type']['icon_class'];
            }
            else
            {
                $f['icon'] = 'fa-file-o';
            }
            $f['type'] = 'file';
            $f['Path'] = $this->getBreadcrumbs($f['category_id']);
            if ($f['category_id'] != 0)
            {
                $file_cat = Category::find($f['category_id']);
                $f['Path'][] = ['id' => $file_cat->id, 'title' => $file_cat->title, 'type' => 'Enable'];
            }
            $file[] = $f;
        }
        foreach ($categories as $category)
        {
            $category['type'] = 'category';
            $category['icon'] = 'fa-folder';
            $category['Path'] = $this->getBreadcrumbs($category['id']);
            $cat[] = $category;
        }
        return datatables()->of(array_merge($cat, $file))->toJson();
    }

    public function editCategory($category_id)
    {
        $messages = [];
        $category = Category::find($category_id);
        $categories = Category::where('user_id', '=', (auth()->check()) ? auth()->id() : 0)->get();
        return view('laravel_file_manager::edit_category', compact('categories', 'category', 'category_id', 'messages'));
    }

    public function storeCategory(Request $request)
    {
        if ($request->ajax())
        {
            $date = $this->getCurrentData();
            if (auth()->check())
            {
                $user_id = auth()->id();
            }
            else
            {
                $user_id = 0;
            }
            $result = DB::transaction(function () use ($request, $date, $user_id) {
                $cat = new Category;
                $cat->title = $request->title;
                $cat->user_id = $user_id;
                $cat->description = $request->description;
                $cat->parent_category_id = $request->parent_category_id;
                $cat->created_by = $user_id;
                $cat->save();
                //update title disc
                $cat->title_disc = 'cid_' . $cat->id . '_uid_' . $user_id . '_' . md5($cat->name) . '_' . time();
                $cat->save();
                //make directory
                $subcats = config('laravel_file_manager.crop_type');
                $path = 'uploads/';
                foreach (Category::all_parents($cat->id) as $parent)
                {
                    if ($parent->parent_category)
                    {
                        $path .= $parent->parent_category->title_disc . '/';
                    }
                }
                $path .= $cat->title_disc;
                foreach ($subcats as $subcat)
                {
                    Storage::disk(config('laravel_file_manager.driver_disk'))->makeDirectory($path . '/files/' . $subcat);
                }
                $category_id = $request->parent_category_id;
                $categories = Category::where('user_id', '=', (auth()->check()) ? auth()->id() : 0)->get();
                $result['success'] = true;
                $messages[] = "Your Category is created";
                return $result;

            });
            return response()->json($result);
        }
    }

    public function showCategory(Request $request)
    {
        $result = $this->show($request->category_id, $request->insert, $request->section);
        return response()->json($result);
    }

    public function trash(Request $request)
    {
        if ($request->type == "file")
        {
            $file = File::find($request->id);
            $file->delete();
        }
        else
        {
            $this->delete($request->id);
        }
        $result = $this->show($request->parent_id, $request->insert, $request->section);
        return response()->json($result);

    }

    private function delete($id)
    {
        $category = Category::with('files', 'child_categories', 'parent_category')->find($id);
        $category->delete();
        if ($category->files)
        {
            foreach ($category->files as $file)
            {
                $file->delete();
            }
        }
        if ($category->child_categories != null)
        {
            foreach ($category->child_categories as $child)
            {
                $id = $child->id;
                $this->delete($id);
            }
        }


    }

    public function bulkDelete(Request $request)
    {
        foreach ($request["items"] as $item)
        {
            if ($item['type'] == "file")
            {
                $file = File::find($item['id']);
                $file->delete();
            }
            else
            {
                $this->delete($item['id']);
            }
        }
        $result = $this->show($item['parent_id'], $request->insert, $request->section);
        return response()->json($result);
    }

    public function storeCropedImage(Request $request)
    {

        $data = str_replace('data:image/png;base64,', '', $request->crope_image);
        $data = str_replace(' ', '+', $data);
        $file = File::find($request->file_id);
        $res = Media::save_croped_image_base64($data, $file, $request->crop_type);
        if ($res)
        {
            $message['success'] = true;
        }
        else
        {
            $message['success'] = false;
        }
        $message['result'] = $res;
        return response()->json($message);
    }

    public function editPicture($file_id)
    {
        $file = File::find($file_id);
        return view('laravel_file_manager::edit_picture', compact('file'));
    }

    public function searchMedia(Request $request)
    {
        $file = [];
        $cat = [];
        $categories = Category::with('user')->select('id', 'title as name', 'user_id', 'parent_category_id', 'description', 'created_at', 'updated_at')
            ->where([
                ['user_id', '=', $this->get_user_id()],
                ['title', 'like', '%' . $request->search . '%']
            ])->get()->toArray();
        $files = File::with('user', 'FileMimeType')->select('id', 'originalName as name', 'user_id', 'file_mime_type_id', 'category_id', 'extension', 'mimeType', 'path', 'created_at', 'updated_at')
            ->where([
                ['user_id', '=', $this->get_user_id()],
                ['originalName', 'like', '%' . $request->search . '%']
            ])->get()->toArray();

        foreach ($files as $f)
        {
            if ($f['file_mime_type']['icon_class'])
            {
                $f['icon'] = $f['file_mime_type']['icon_class'];
            }
            else
            {
                $f['icon'] = 'fa-file-o';
            }
            $f['type'] = 'file';

            $f['Path'] = $this->get_breadcrumbs($f['category_id']);
            if ($f['category_id'] != 0)
            {
                $file_cat = Category::find($f['category_id']);
                $f['Path'][] = ['id' => $file_cat->id, 'title' => $file_cat->title, 'type' => 'Enable'];
            }

            $file[] = $f;
        }

        foreach ($categories as $category)
        {
            $category['type'] = 'category';
            $category['icon'] = 'fa-folder';
            $category['Path'] = $this->get_breadcrumbs($category['id']);
            $cat[] = $category;
        }

        return datatables()->of(array_merge($cat, $file))->toJson();
    }

    /**
     * @return string
     */
    public function getCurrentData()
    {
        $mytime = Carbon::now();
        return $mytime->toDateString();
    }

    public function ShowList(Request $request)
    {
        return view('laravel_file_manager::content_list');
    }

    public function showListCategory(Request $request)
    {
        if ($request->section != null && $request->section != false && $request->section != 'false')
        {
            $LFM = session()->get('LFM');
            $trueMimeType = $LFM[$request->section]['options']['true_mime_type'];
        }
        else
        {
            $trueMimeType = false;
        }
        $file = [];
        $cat = [];
        if ($request->id == 0)
        {
            $categories = Category::with(['child_categories', 'parent_category', 'user'])->select('id', 'title as name', 'user_id')->where([
                ['parent_category_id', '=', '0'],
                ['user_id', '=', $this->getUserId()]
            ])->get()->toArray();

            if ($trueMimeType)
            {
                $files = File::with('user', 'FileMimeType')->select('id', 'originalName as name', 'user_id', 'mimeType', 'category_id', 'file_mime_type_id', 'created_at', 'updated_at')->where([
                    ['category_id', '=', '0'],
                    ['user_id', '=', $this->getUserId()]
                ])->whereIn('mimeType', $trueMimeType)->get()->toArray();
            }
            else
            {
                $files = File::with('user', 'FileMimeType')->select('id', 'originalName as name', 'user_id', 'mimeType', 'category_id', 'file_mime_type_id', 'created_at', 'updated_at', 'size')->where([
                    ['category_id', '=', '0'],
                    ['user_id', '=', $this->getUserId()]
                ])->get()->toArray();
            }
        }
        else
        {
            $category = Category::with('files', 'child_categories', 'parent_category')->find($request->id);
            $categories = $category->user_child_categories->toArray();
            $files = $category->UserFiles($trueMimeType)->toArray();
        }
        foreach ($categories as $category)
        {
            $category['type'] = 'category';
            $category['icon'] = 'fa-folder';
            $cat[] = $category;
        }
        foreach ($files as $f)
        {
            if ($f['file_mime_type']['icon_class'])
            {
                $f['icon'] = $f['file_mime_type']['icon_class'];
            }
            else
            {
                $f['icon'] = 'fa-file-o';
            }
            $f['type'] = 'file';
            $file[] = $f;
        }
        return datatables()->of(array_merge($cat, $file))->toJson();
    }

    public function getBreadcrumbs($id)
    {
        $breadcrumbs[] = ['id' => 0, 'title' => 'media', 'type' => 'Enable'];
        $parents = Category::all_parents($id);
        if ($parents)
        {
            foreach ($parents as $parent)
            {
                if ($parent->parent_category)
                {
                    $breadcrumbs[] = ['id' => $parent->parent_category->id, 'title' => $parent->parent_category->title, 'type' => 'Enable'];
                }
            }
        }
        return $breadcrumbs;
    }

    /**
     * @param $id
     * @return mixed
     */
    private function show($id, $insert = false, $callback = false, $section = false)
    {
        $breadcrumbs = $this->getBreadcrumbs($id);
        if ($section)
        {
            $LFM = session()->get('LFM');
            $trueMimeType = $LFM[$section]['options']['true_mime_type'];

        }
        else
        {
            $trueMimeType = false;
            $result['button_upload_link'] = route('LFM.FileUpload', ['category_id' => $id, 'callback' => 'refresh', 'section' => 'false']);
        }
        if ($id == 0)
        {
            $files = File::get_uncategory_files($trueMimeType);
            $categories = Category::get_root_categories();
            $category = false;
            $result['html'] = view('laravel_file_manager::content', compact('categories', 'files', 'category', 'breadcrumbs', 'insert', 'section'))->render();
            $result['message'] = '';
            $result['button_upload_link'] = route('LFM.FileUpload', ['category_id' => $id, 'callback' => 'refresh', 'section' => LFM_CheckFalseString($section), 'callback' => LFM_CheckFalseString($callback)]);
            $result['parent_category_id'] = $id;
            $result['parent_category_name'] = 'media';
            $result['button_category_create_link'] = route('LFM.ShowCategories.Create', ['category_id' => $id, 'callback' => LFM_CheckFalseString($callback), 'section' => LFM_CheckFalseString($section)]);
            $result['success'] = true;
        }
        else
        {
            $category = Category::with('parent_category')->find($id);
            $breadcrumbs[] = ['id' => $category->id, 'title' => $category->title, 'type' => 'DisableLink'];
            $categories = $category->user_child_categories;
            $files = $category->UserFiles($trueMimeType);
            $result['html'] = view('laravel_file_manager::content', compact('categories', 'files', 'category', 'breadcrumbs', 'insert', 'section'))->render();
            $result['message'] = '';
            $result['parent_category_id'] = $id;
            $result['parent_category_name'] = $category->title;
            $result['button_category_create_link'] = route('LFM.ShowCategories.Create', ['category_id' => $id, 'callback' => 'refresh']);
            $result['success'] = true;
        }
        return $result;
    }

    public function trash(Request $request)
    {
        if ($request->type == "file")
        {
            $file = File::find($request->id);
            $file->delete();
        $options = $this->get_section_options($request->section);
        if ($options['success'])
        {
            $check_options = $this->check_section_options($request->section, $options['options'], $request->items);
            if ($check_options['success'])
            {
                $datas = $this->create_all_insert_data($request);
                $view['grid'] = $this->GridInsertedView($datas, $request->section);
                $view['small'] = $this->SmallInsertedView($datas, $request->section);
                $view['thumb'] = $this->MediumInsertedView($datas, $request->section);
                $view['large'] = $this->LargeInsertedView($datas, $request->section);
                $view['list'] = $this->ListInsertedView($datas, $request->section);
                $result_session = $this->set_selected_file_to_session($request, $request->section, $datas);
                $result['view'] = $view;
            }
            else
            {
                $datas['success'] = false;
                $datas['error'] = $check_options['error'];
            }
        }
        else
        {
            $this->delete($request->id);
        }
        $result = $this->show($request->parent_id, $request->insert, $request->section);
        $result['data'] = $datas;
        return response()->json($result);
    }

    private function delete($id)
    {
        $category = Category::with('files', 'child_categories', 'parent_category')->find($id);
        $category->delete();
        if ($category->files)
        {
            foreach ($category->files as $file)
            {
                $file->delete();

        if (session()->has('LFM'))
        {
            $LFM = session()->get('LFM');
            if (isset($LFM[$section]))
            {
                //check options
                if ($LFM[$section]['options'])
                {
                    $result['options'] = $LFM[$section]['options'];
                    $result['success'] = true;
                    return $result;
                }
                else
                {
                    $result['success'] = false;
                    return $result;
                }
            }
        }
        if ($category->child_categories != null)
        {
            foreach ($category->child_categories as $child)
            {
                $id = $child->id;
                $this->delete($id);
            }
        }
        else
        {
            $result['success'] = false;
            $result['error'] = '';
            return $result;
        }


    }

    public function bulkDelete(Request $request)
    {
        foreach ($request["items"] as $item)
        {
            if ($item['type'] == "file")
            {
                $file = File::find($item['id']);
                $file->delete();
            }
            else
            {
                $this->delete($item['id']);
            }
        }
        $result = $this->show($item['parent_id'], $request->insert, $request->section);
        return response()->json($result);
    }

    public function storeCropedImage(Request $request)
    {
        $data = str_replace('data:image/png;base64,', '', $request->crope_image);
        $data = str_replace(' ', '+', $data);
        $file = File::find($request->file_id);
        $res = Media::save_croped_image_base64($data, $file, $request->crop_type);
        if ($res)
        {
            $message['success'] = true;
        }
        else
        {
            $message['success'] = false;
        $datas = [];
        $section = $this->GetSession($request->section);
        if (isset($section['selected']))
        {
            foreach ($request->items as $item)
            {
                $id = $item['id'];
                $status = LFM_FindSessionSelectedId($section['selected'], $id);
                if (!$status)
                {
                    $full_url = route('LFM.DownloadFile', ['type' => 'ID', 'id' => $id, 'size_type' => $item['type'], 'default_img' => '404.png'
                        , 'quality' => $item['quality'], 'width' => $item['width'], 'height' => $item['height']
                    ]);
                    $protocol = strtolower(substr($_SERVER["SERVER_PROTOCOL"], 0, 5)) == 'https' ? 'https' : 'http';
                    $url = str_replace($protocol, '', $full_url);
                    $url = str_replace('://', '', $url);
                    $url = str_replace($_SERVER['HTTP_HOST'], '', $url);
                    $file = File::find($id);
                    $image_type = config('laravel_file_manager.allowed_pic');
                    if (in_array($file->mimeType, $image_type))
                    {
                        $icon = 'image';
                    }
                    else
                    {
                        $icon = $file->FileMimeType->icon_class;
                    }

                    if (!$file->user)
                    {
                        $user = 'public';
                    }
                    else
                    {
                        $user = $file->user->name;
                    }

                    switch ($request->type)
                    {
                        case "orginal":
                            $file_title_disc = $file->filename;
                            $version = $file->versioin;
                            break;
                        case "large":
                            $file_title_disc = $file->large_filename;
                            $version = $file->large_version;
                            break;
                        case "medium":
                            $file_title_disc = $file->medium_filename;
                            $version = $file->medium_version;
                            break;
                        case "small":
                            $file_title_disc = $file->small_filename;
                            $version = $file->small_version;
                            break;
                        default:
                            $file_title_disc = $file->filename;
                            $version = $file->versioin;
                            break;
                    }
                    $data['full_url'] = $full_url;
                    $data['url'] = $url;
                    $data['file'] = [
                        'id' => $file->id,
                        'name' => $file->originalName,
                        'type' => $item['type'],
                        'width' => $item['width'],
                        'height' => $item['height'],
                        'quality' => $item['quality'],
                        'title_file_disc' => $file_title_disc,
                        'created' => $file->created_at,
                        'updated' => $file->updated_at,
                        'user' => $user,
                        'icon' => $icon,
                        'size' => $file->size,
                        'version' => $version
                    ];
                    $data['success'] = true;
                    $data['message'] = "File with ID :" . $id . ' Inserted';
                    $datas[] = $data;
                }

            }
        }
        $message['result'] = $res;
        return response()->json($message);
    }

    public function editPicture($file_id)
    {
        $file = File::find($file_id);
        return view('laravel_file_manager::edit_picture', compact('file'));
    }

    public function getSectionOptions($section)
    {
        if (session()->has('LFM'))
        {
            $LFM = session()->get('LFM');
            if (isset($LFM[$section]))
            {
                if ($LFM[$section]['options'])
                {
                    $result['options'] = $LFM[$section]['options'];
                    $result['success'] = true;
                    return $result;
                }
                else
                {
                    $result['success'] = false;
                    return $result;
                }
            }
            else
            {
                $result['success'] = false;
                $result['error'] = '';
                return $result;
            }
        }
        else
        {
            $result['success'] = false;
            $result['error'] = '';
            return $result;
        }

        }
        return $result;
    }

    public function GridInsertedView($datas, $section = false)
    {
        //return grid inserted view
        $view = view('laravel_file_manager::grid_inserted_view', compact('datas', 'section'))->render();
        return $view;
    }

    public function SmallInsertedView($datas, $section = false)
    {
        //return grid inserted view
        $view = view('laravel_file_manager::small_inserted_view', compact('datas', 'section'))->render();
        return $view;
    }

    public function MediumInsertedView($datas, $section = false)
    {
        $view = view('laravel_file_manager::medium_inserted_view', compact('datas', 'section'))->render();
        return $view;
    }

    public function LargeInsertedView($datas, $section = false)
    {
        $view = view('laravel_file_manager::large_inserted_view', compact('datas', 'section'))->render();
        return $view;
    }


    public function ListInsertedView($datas, $section = false)
    {
        $view = view('laravel_file_manager::list_inserted_view', compact('datas', 'section'))->render();
        return $view;
    }


    private function merge_to_selected($selected, $data)
    {
        $status = LFM_FindSessionSelectedId($selected, $data['file']['id']);
        if ($status == false)
        {
            $result ['data'] = $data;

        }
        else
        {
            $result['error'] = 'The File ID ' . $data['file']['id'] . ' is repeated';
        }


        return $result;

    }

    public function GetSession($section)
    {
        return LFM_GetSection($section);

    }

    public function DeleteSessionInsertItem($name, $id)
    {
        $LFM = session()->get('LFM');
        if ($LFM[$name])
        {
            $selected = $LFM[$name]['selected'];
            foreach ($selected as $key => $value)
            {
                if ($id == $value['file']['id'])
                {
                    unset($selected[$key]);
                }
            }
            $LFM[$name]['selected'] = $selected;
            session()->put('LFM', $LFM);
            return $LFM;

        }
        else
        {
            return false;
        }


    }

    public function DeleteSelectedPostId(Request $request)
    {
        dd($request->all());
    }

    public function getUserId()
    {
        if (auth()->check())
        {
            $user_id = auth()->id();
        }
        else
        {
            $user_id = 0;
        }
        return $user_id;
    }

    public function getCurrentData()
    {
        $mytime = Carbon::now();
        return $mytime->toDateString();
    }
}

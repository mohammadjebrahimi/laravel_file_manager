<div class="container-fluid">
    <table class="table table-hover">
        <thead>
        <tr>
            <th>Row</th>
            <th>ID</th>
            <th>Name</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        @php( $i =0)
        @foreach($data as $file_item)
            <tr id="{{$section}}_{{$file_item['file']['id']}}_trash_insert_li">
                <td>{{$i}}</td>
                @php($i++)
                <td>{{$file_item['file']['id']}}</td>
                <td><a href="{{$file_item['full_url']}}">{{$file_item['file']['name']}}</a></td>
                <td>
                    <a class="grid-row-delete pull-right myicon" id="{{$section}}_trash_inserted" data-type="file" data-section="{{$section}}" data-file_id="{{$file_item['file']['id']}}">
                        <i class="fa fa-trash" style="color: red;"></i>
                    </a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@include('laravel_file_manager::selected.helpers.inline_js')

@extends('layouts.app')

@section('content')
<h1 class="margin-b-40">RAG Application</h1>

<!-- Form to add new text for embedding -->
<!-- <form class="margin-b-40" method="POST">
    @csrf
    <label for="name">Add New Text</label>
    <input type="text" id="name" name="add_text" placeholder="Enter text" required />
    <button type="submit">Add Embedding</button>
</form> -->

<!-- Search box -->
<label for="searchBox">Search Embeddings</label>
<input class="margin-b-40" type="text" id="searchBox" placeholder="Type to search..." />

<p id="results" class="p-highlight" data-total-count="{{ number_format($count) }}">{{ number_format($count) }} items (<span id="shown-count">0</span> shown).</p>

<!-- Table displaying all embeddings -->
<h2>Stored Embeddings</h2>
<table id="embeddingsTable">
    <thead>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Content</th>
            <th>Meta Image</th>
            <th>Page URL</th>
            <th>Source URL</th>
            <th>Source Format</th>
            <th>Date</th>
            <th>Tags</th>
            <th>Intermediate Formatting</th>
            <th>Similarity</th>
            <!-- <th>Edit</th> -->
        </tr>
    </thead>
    <tbody>
    </tbody>
</table>

<!-- Modal form to edit embeddings -->
<!-- <div id="editModal" class="modal">
    <div class="modal__wrapper flex-col-center">
        <h2>Edit Embedding</h2>
        <form class="flex-col-center" method="POST">
            @csrf
            <input type="hidden" name="edit_id" id="edit_id">
            <textarea name="edit_text" id="edit_text" rows="20" cols="80" required></textarea>
            <div>
                <button type="submit">Update Embedding</button>
                <button type="button" onclick="hideModal()">Cancel</button>
            </div>
        </form>
    </div>
</div> -->
@endsection
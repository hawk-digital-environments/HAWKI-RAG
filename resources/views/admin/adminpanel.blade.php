<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Document</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous"></script>
    @vite('resources/js/app.js')

    <style>
        html,body {
            height: 100%;
        }
        .container-fluid{
            height: 100%;
        }
        .row{
            height: 100%;
        }
        .col-6{
            padding: 2rem;
            border: 1px solid black;
            height: 100%;
        }
        input{
            width: 100%;
            margin-bottom: .5rem;
        }
        input[placeholder]{
            color: gray;
            font-style: italic;
        }
        input[value]{
            color: black;
            font-style: normal;
        }
        #log{
            width: 100%;
            height: 100%;
            margin-bottom: .5rem;
            overflow-y: scroll;
        }
        pre {
            font-size: 1em;
            display: flex;
            flex-direction: column;
        }
        .string { color: green; }
        .number { color: darkorange; }
        .boolean { color: blue; }
        .null { color: magenta; }
        .key { color: red; }
    </style>

</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-6">
                <label>sitemapUrl</label>
                <input type="text" id="sitemapUrl" value="https://www.hawki.info/sitemap.xml">
                <label>label</label>
                <input type="text" id="label" value="test">
                <label>maxPages</label>
                <input type="text" id="maxPages" value="1">
                <label>maxRpm</label>
                <input type="text" id="maxRpm" value="60">
                <label>maxConcurrency</label>
                <input type="text" id="maxConcurrency" value="4">
                <label>requestDelay</label>
                <input type="text" id="requestDelay" value="0">

                <button class="send-btn" onclick="sendRequest()">Send</button>
                <button class="stop-btn" onclick="stopPolling()">Stop</button>
            </div>
            <div class="col-6">
                <div id="log"></div>
            </div>
        </div>


    </div>
</body>
</html>


<script>
    let jobId;
    async function sendRequest(){
        await fetch('/requestScrape', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                url: document.querySelector('#sitemapUrl').value,
                label: document.querySelector('#label').value,
                maxPages: document.querySelector('#maxPages').value,
                outputDir: '',
                skipImages: false,
                imageExceptions: null,
                dateSelector: '',
                maxConcurrency: document.querySelector('#maxConcurrency').value,
                maxRpm: document.querySelector('#maxRpm').value,
                requestDelay: document.querySelector('#requestDelay').value,
                discoveryMod: false,
            })
        })
        .then(res => res.json())
        .then(json => {
            if(json.success){
                jobId = json.result.jobId;
                startPolling();
                document.querySelector('#log').innerHTML =
                    `<b>Job Submitted successfully.</b><br>
                        Job ID: ${jobId}<br></br>
                    `;
            }
            else{
                jobId = json.jobId;
                document.querySelector('#log').innerHTML =
                    `<b>Job Submitted successfully.</b><br>
                        Job ID: ${jobId}<br></br>
                        Errors: ${json.result.error}<br></br>
                        Warnings: ${json.result.warnings}<br></br>
                    `;
            }
        })
    }



    let interval;
    async function startPolling(){
        console.log('startPolling')
        interval = setInterval(async function () {
            await poll(jobId);
        }, 500)
    }
    function stopPolling(){
        clearInterval(interval);
    }
    async function poll(jobId) {
        const response = await fetch('/getScrapeInformation', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ 'jobId': jobId })
        });
        const json = await response.json();
        const d = json.data;

        if(d.stage === 'Scrape-Completed'){
            stopPolling(interval);
        }
        document.querySelector('#log').innerHTML = `
        <b>id:</b> ${d.id}<br>
        <b>job </b>id: ${d.job_id} <br>
        <b>label:</b> ${d.label}<br>
        <b>stage:</b>  ${d.stage}<br>
        <b>total URLs:</b> ${d.stats.total_urls}<br>
        <b>completed URLs:</b> ${d.stats.completed_urls}<br><br>

        <b>stats:</b>
        <pre>

        sessions: ${d.stats.sessions}
        requests: ${d.stats.requests}
        total_urls: ${d.stats.total_urls}
        target_urls: ${d.stats.target_urls}
        completed_urls: ${d.stats.completed_urls}
        failed_urls: ${d.stats.failed_urls}
        current_url: ${d.stats.current_url}
        errors: ${d.stats.errors}
        warnings: ${d.stats.warnings}
        pdfs_downloaded: ${d.stats.pdfs_downloaded}
        images_downloaded: ${d.stats.images_downloaded}
        started_at: ${d.stats.started_at}
        completed_at: ${d.stats.completed_at}
        created_at: ${d.stats.created_at}
        updated_at: ${d.stats.updated_at}
</pre>
`;
    }


</script>

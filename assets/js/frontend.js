function smdDownloadAudio(audioUrl, title) {
    const link = document.createElement('a');
    link.href = audioUrl;
    link.download = title + '.mp3';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Track download
    smdTrackStats('audio_download', title);
}

function smdDownloadVideo(videoUrl, title) {
    const link = document.createElement('a');
    link.href = videoUrl;
    link.download = title + '.mp4';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Track download
    smdTrackStats('video_download', title);
}

function smdTrackStats(action, data) {
    fetch(smd_ajax.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=smd_track_stats&nonce=' + smd_ajax.nonce + '&action=' + encodeURIComponent(action) + '&data=' + encodeURIComponent(data)
    });
}

// Initialize players with visual effects
document.addEventListener('DOMContentLoaded', function() {
    // Add visualizer effect to audio players
    const audioPlayers = document.querySelectorAll('.smd-audio-player');
    audioPlayers.forEach(function(player) {
        const audio = player.querySelector('audio');
        if (audio) {
            audio.addEventListener('play', function() {
                // Add visualizer effect
                const bars = player.querySelectorAll('.smd-waveform-bar');
                bars.forEach(function(bar, index) {
                    animateBar(bar, index);
                });
                
                // Track play
                smdTrackStats('audio_play', player.querySelector('h4').textContent);
            });
        }
    });
    
    // Add visual effects to video players
    const videoPlayers = document.querySelectorAll('.smd-video-player');
    videoPlayers.forEach(function(player) {
        const video = player.querySelector('video');
        if (video) {
            video.addEventListener('play', function() {
                // Track play
                smdTrackStats('video_play', player.querySelector('h4').textContent);
            });
        }
    });
    
    // Animate waveform bars
    function animateBar(bar, index) {
        const originalHeight = parseInt(bar.style.height) || 30;
        let direction = 1;
        
        setInterval(function() {
            let newHeight = parseInt(bar.style.height) || originalHeight;
            newHeight += 5 * direction;
            
            if (newHeight > originalHeight + 30 || newHeight < originalHeight - 10) {
                direction *= -1;
            }
            
            bar.style.height = newHeight + 'px';
        }, 200 + index * 50);
    }
});

// Donation functionality
function smdProcessDonation(amount, currency, paymentMethod) {
    fetch(smd_ajax.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=smd_process_payment&nonce=' + smd_ajax.nonce + 
              '&amount=' + amount + 
              '&currency=' + currency + 
              '&payment_method=' + paymentMethod + 
              '&media_id=0'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Donation processed successfully: ' + data.transaction_id);
        } else {
            alert('Error processing donation: ' + data.message);
        }
    });
}
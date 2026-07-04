Tìm hiTìmsddTôi cần bạn lên plan và nghiên để tạo 1 con game đánh bài Catte online vui vẻ.
Có thể chạy trên Share Hosting.

Tech Stack mong muốn (bạn review khả thi hay không)
Nếu muốn realtime mượt hơn nhưng không muốn tự quản VPS:

Stack

Frontend:

Vue/React hoặc Blade + Alpine

Backend:

Laravel + MySQL

Realtime service bên ngoài:

Pusher
Ably
Supabase Realtime

Hostinger Web/Cloud có thể mở kết nối WebSocket ra ngoài, nhưng không phù hợp để làm WebSocket server nhận kết nối từ browser. Vì vậy Laravel xử lý logic game, còn realtime event đẩy qua dịch vụ ngoài.

Kiến trúc:

Browser
  ↓ HTTP
Laravel + MySQL on Hostinger
  ↓ outgoing WebSocket/API
Pusher/Ably/Supabase Realtime
  ↓
Browser nhận event realtime
Phù hợp khi
Muốn animation/notify nhanh hơn polling
Không muốn mua VPS
Có chat trong phòng
Muốn người chơi thấy ngay khi đối thủ đánh bài


Dưới đây là luật chơi Cát Tê/Cắt Tê 6 lá đã được chuẩn hóa từ bài bạn gửi và đối chiếu thêm nhiều nguồn. Lưu ý: trò này có nhiều biến thể theo vùng/bàn, nên nên chốt luật trước khi chơi.

1. Mục tiêu của Cát Tê

Cát Tê không giống Tiến Lên. Mục tiêu chính là có “tồn” trong 4 vòng đầu để được vào vòng 5–6, rồi thắng vòng cuối cùng. Một số luật cũng xử thắng ngay nếu một người thắng cả 4 vòng đầu. Nguồn quốc tế mô tả Cắt Tê là trick-taking game, mục tiêu là thắng vòng cuối hoặc thắng cả 4 trick đầu.

2. Bộ bài và số người chơi

Dùng bộ bài Tây 52 lá, bỏ Joker. Mỗi người được chia 6 lá. Số người chơi phổ biến là 2–6 người; bài bạn gửi ghi 2–5 người, nhưng nhiều nguồn khác ghi 2–6 người.

Thứ tự mạnh yếu của lá bài:

A > K > Q > J > 10 > 9 > 8 > 7 > 6 > 5 > 4 > 3 > 2

Thứ tự chất thường dùng khi cần so phụ:

Cơ ♥ > Rô ♦ > Chuồn ♣ > Bích ♠

3. Các khái niệm quan trọng

Tồn: lá thắng một vòng. Người có tồn nghĩa là đã thắng ít nhất một vòng trong 4 vòng đầu.

Thiệp: lá bị úp xuống hoặc lá đánh ra nhưng bị lá khác lớn hơn cùng chất đè.

Gục tùng / chết tùng: sau 4 vòng đầu mà người chơi không có tồn nào thì bị loại khỏi ván.

Thắng tùng: sau 4 vòng đầu, nếu chỉ còn một người có tồn, người đó thắng ngay.

Chưng / trưng: vòng thứ 5, nơi những người còn tồn bắt đầu bước vào giai đoạn quyết định.

Bài bạn gửi cũng định nghĩa các thuật ngữ này, và GameVH/Vinpearl mô tả tương tự về thiệp, tồn, chết tùng, thắng tùng.

4. Luật thắng trắng

Ngay sau khi chia bài, nếu có bộ đặc biệt thì có thể thắng luôn, không cần đánh. Thứ tự ưu tiên thường là:

Tứ quý
6 lá cùng chất
6 lá đều nhỏ hơn 6

Nếu nhiều người cùng thắng trắng thì so theo độ mạnh của bộ bài. GameVH ghi rõ thứ tự: tứ quý > 6 lá cùng chất > 6 lá nhỏ hơn 6.

Điểm cần chú ý: bài bạn gửi ghi “5 quân bài đều nhỏ hơn 6”, nhưng vì mỗi người được chia 6 lá, nhiều nguồn khác ghi là 6 lá nhỏ hơn 6. Khả năng cao “5 quân” là lỗi viết hoặc biến thể riêng.

5. Cách đánh 4 vòng đầu

Ván Cát Tê có tối đa 6 vòng.

Ở vòng 1–4:

Người đi đầu đánh ra 1 lá bài ngửa. Người tiếp theo có thể:

Đánh 1 lá cùng chất và lớn hơn lá đang thắng trên bàn.
Hoặc úp xuống 1 lá bất kỳ, gọi là thiệp.

Kết thúc vòng, người có lá ngửa lớn nhất cùng chất với lá dẫn vòng sẽ thắng vòng đó, giữ được “tồn” và được đi đầu vòng tiếp theo. GameVH và Vinagames đều mô tả nguyên tắc: người chơi sau muốn thắng phải đánh lá lớn hơn cùng chất; người thắng vòng sẽ dẫn vòng kế tiếp.

Ví dụ:

A đánh 9♥.
B đánh Q♥ nên đang thắng A.
C không có hoặc không muốn đánh ♥ lớn hơn, úp 1 lá.
D đánh A♥.

Kết quả: D thắng vòng, lá A♥ là tồn, D đi đầu vòng sau.

6. Sau 4 vòng đầu

Sau khi xong vòng 4:

Nếu người nào không thắng vòng nào, tức không có tồn, thì bị gục tùng và dừng chơi.

Nếu chỉ có một người có tồn, người đó thắng ngay, gọi là thắng tùng.

Nếu có từ hai người trở lên có tồn, những người đó tiếp tục vào vòng 5 và vòng 6. GameVH ghi rõ: ai không giữ được lá nào sau 4 vòng đầu thì thua ngay; nếu chỉ một người có tồn thì thắng luôn; nếu nhiều người có tồn thì tiếp tục vòng 5.

7. Vòng 5: Chưng

Vòng 5 là vòng “chưng”.

Người thắng vòng 4 thường là người chưng trước, đánh ra 1 lá. Các người còn lại đánh 1 lá của mình. Tùy luật bàn, lá của người khác có thể úp trước rồi lật ra sau. Vinagames mô tả vòng 5 là chỉ người dẫn đầu mở bài, các người khác đưa bài úp/ẩn, sau đó xác định người thắng vòng 5.

Người thắng vòng 5 sẽ dẫn vòng cuối.

8. Vòng 6: Quyết định thắng ván

Ở vòng 6, những người còn lại lật lá cuối cùng. Người có lá thắng vòng 6 sẽ thắng cả ván.

Nói ngắn gọn:

4 vòng đầu để giành quyền sống sót.
Vòng 5 để giành quyền dẫn cuối.
Vòng 6 để quyết định người thắng ván.

GameVH cũng ghi: vòng 6 tất cả người chơi ngửa lá trên tay, tính tồn tương tự các vòng trước, ai có tồn vòng 6 thì thắng ván.

9. Luật Ách và tính điểm/phạt

Phần này là luật phụ, mỗi bàn có thể khác nhau.

Bài bạn gửi có nói về thưởng/phạt lá Ách: nếu Ách bị thiệp hoặc người chơi gục tùng còn giữ Ách thì có thể bị phạt. GameVH cũng ghi biến thể phạt “thối Át” nếu người gục tùng vẫn còn Át.

Nếu chơi vui không cá cược, có thể quy đổi đơn giản:

Thắng ván: +1 điểm
Gục tùng: -1 điểm
Thối Ách: -1 điểm mỗi lá, nếu bàn có áp dụng
Thắng trắng hoặc thắng tùng: +2 điểm, nếu muốn tăng độ kịch tính
Tóm tắt luật lõi dễ nhớ

Cát Tê = 6 lá, tối đa 6 vòng.

Trong 4 vòng đầu, muốn ăn lá trước thì phải dùng lá cùng chất và lớn hơn. Không ăn thì úp bài. Ai thắng vòng thì có tồn.

Sau 4 vòng, ai không có tồn thì bị loại. Nếu chỉ một người có tồn thì người đó thắng. Nếu còn nhiều người có tồn thì đánh tiếp vòng 5 và vòng 6. Người thắng vòng 6 là người thắng ván.

Điểm quan trọng nhất: không phải cứ bài lớn là thắng, phải lớn đúng chất và phải sống tới vòng cuối.

